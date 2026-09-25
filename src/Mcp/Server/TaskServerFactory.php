<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Server;
use PhpMcp\Server\ServerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the task-loom MCP server role (SPEC §11): task_create, task_update,
 * task_list, task_get over Streamable HTTP.
 *
 * The builder's withTool() hand-registers each handler method with an
 * explicit JSON input schema. Explicit schemas keep the wire contract
 * stable regardless of how the SDK's docblock schema generation evolves,
 * and they document the gate visually: there is no 'enabled' parameter
 * anywhere in any write tool's schema.
 */
final class TaskServerFactory
{
    private const SERVER_NAME = 'task-loom';
    private const SERVER_VERSION = '1.0.0';

    /**
     * The steps property shared by task_create / task_update (SPEC §13.2):
     * an array of levels (each level an array of steps that run in
     * parallel; levels run in sequence), each step an object of title +
     * brief + optional toolbox. Validation is re-run server-side with
     * indexed error messages; this schema is the coarse gate.
     *
     * @return array<string, mixed>
     */
    private static function stepsSchema(string $description): array
    {
        return [
            'type' => ['array', 'null'],
            'description' => $description,
            'items' => [
                'type' => 'array',
                'description' => 'One level: the steps in it run in parallel. Levels run in sequence.',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'description' => 'One step: a brief plus an optional toolbox. Its inputs are the previous level\'s outputs; the task\'s own brief is the final consumer of all step outputs.',
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Short step name, unique within the task in practice.'],
                        'brief' => ['type' => 'string', 'minLength' => 1, 'description' => 'What this step must accomplish.'],
                        'toolbox_mode' => ['type' => 'string', 'enum' => ['tags', 'explicit'], 'description' => 'How this step\'s toolbox is resolved. Default: explicit.', 'default' => 'explicit'],
                        'toolbox' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags or explicit tool names for this step. Default: empty.', 'default' => []],
                    ],
                    'required' => ['title', 'brief'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function build(): Server
    {
        $builder = new ServerBuilder()
            ->withServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            ->withCapabilities(ServerCapabilities::make())
            ->withInstructions(implode("\n", [
                'Task management for task-loom (SPEC §10).',
                'Writes are gated: every task_create and task_update persists disabled and lands in the human approval queue (SPEC §4.3). You cannot create or enable tasks directly.',
                'Tasks may declare a step graph (SPEC §13): an array of levels; steps within a level run in parallel, levels run in sequence. Steps are optional — a task with no steps runs as a single unit.',
                'Read tools: task_list, task_get. Write tools: task_create, task_update.',
            ]))
            ->withLogger($this->logger)
            ->withContainer($this->container);

        $this->registerTaskTools($builder);

        return $builder->build();
    }

    private function registerTaskTools(ServerBuilder $builder): void
    {
        $builder
            ->withTool(
                handler: [TaskTools::class, 'create'],
                name: 'task_create',
                description: 'Create a new task. The task is persisted disabled and appears in the human approval queue — there is no way to create an enabled task through this tool (SPEC §4.3).',
                annotations: ToolAnnotations::make(
                    title: 'Create task',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: false,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short task name.', 'minLength' => 1, 'maxLength' => 255],
                        'brief' => ['type' => 'string', 'description' => 'What the task should accomplish. For a stepped task this brief is the final consumer: it runs after all steps, with every step\'s output available as inputs.'],
                        'kind' => ['type' => 'string', 'enum' => ['run', 'session'], 'description' => 'Task kind; v1 ships run only.'],
                        'toolboxMode' => ['type' => 'string', 'enum' => ['tags', 'explicit'], 'description' => 'How the toolbox list is resolved at run time.'],
                        'toolbox' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags or explicit tool names, depending on toolboxMode.'],
                        'schedule' => ['type' => ['string', 'null'], 'description' => 'Optional cron-style schedule; v1 runs are manual, so leave null.'],
                        'steps' => self::stepsSchema('Optional step graph: an array of levels, each level an array of steps. Steps in a level run in parallel; levels run in sequence. Omit for a single-unit task.'),
                    ],
                    'required' => ['title', 'brief', 'kind', 'toolboxMode', 'toolbox'],
                ],
            )
            ->withTool(
                handler: [TaskTools::class, 'update'],
                name: 'task_update',
                description: 'Update a task. Enabled tasks are immutable (SPEC §4.4): updating one returns a disabled replacement draft; the original keeps running. Draft tasks are edited in place.',
                annotations: ToolAnnotations::make(
                    title: 'Update task',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'taskId' => ['type' => 'integer', 'description' => 'ID of the task to update.', 'minimum' => 1],
                        'changes' => [
                            'type' => 'object',
                            'description' => 'Fields to change. Omitted fields are kept. Keys are snake_case field names.',
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                                'brief' => ['type' => 'string'],
                                'kind' => ['type' => 'string', 'enum' => ['run', 'session']],
                                'toolbox_mode' => ['type' => 'string', 'enum' => ['tags', 'explicit']],
                                'toolbox' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'schedule' => ['type' => ['string', 'null'], 'description' => 'Cron-style schedule, or null to clear.'],
                                'steps' => self::stepsSchema('Replace the task\'s entire step graph with this array of levels ([] clears all steps). Omit the key to leave the graph untouched.'),
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['taskId', 'changes'],
                ],
            )
            ->withTool(
                handler: [TaskTools::class, 'list'],
                name: 'task_list',
                description: 'List tasks, newest first. Read-only.',
                annotations: ToolAnnotations::make(
                    title: 'List tasks',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'includeArchived' => ['type' => 'boolean', 'description' => 'Include archived tasks. Default false.', 'default' => false],
                    ],
                ],
            )
            ->withTool(
                handler: [TaskTools::class, 'get'],
                name: 'task_get',
                description: "Get one task's full record, including its replacement chain. Read-only.",
                annotations: ToolAnnotations::make(
                    title: 'Get task',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'taskId' => ['type' => 'integer', 'description' => 'Task ID.', 'minimum' => 1],
                    ],
                    'required' => ['taskId'],
                ],
            );
    }
}
