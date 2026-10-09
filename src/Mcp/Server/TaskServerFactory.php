<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Admin\RunDigest;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Builds the task-loom MCP server role (SPEC §10, §11): task_create,
 * task_update, task_list, task_get over Streamable HTTP, plus the
 * reviewer's read-only pair run_review and run_read_log (SPEC §10's
 * improvement cycle).
 *
 * Each handler is registered with an explicit JSON input schema. Explicit
 * schemas keep the wire contract stable regardless of how the SDK's schema
 * generation evolves, and they document the gate visually: there is no
 * 'enabled' parameter anywhere in any write tool's schema.
 *
 * The server is built per request and driven by the controller — the SDK's
 * HTTP transport is a PSR-7 request handler, not a web server, so there is no
 * socket and no event loop here (see docs/design/MCP_SDK_MIGRATION.md).
 *
 * The reviewer tools are here rather than on a second server because SPEC
 * §10's reviewer is an ordinary task whose toolbox is the harness's own
 * tools: what it can reach is a deployment choice (the operator connects
 * task-loom to itself as an MCP client), not a separate surface to build.
 */
final class TaskServerFactory
{
    private const SERVER_NAME = 'task-loom';
    private const SERVER_VERSION = '1.0.0';

    /** One hour, matching the SDK's documented default. */
    private const SESSION_TTL_SECONDS = 3600;

    private ?Psr16SessionStore $sessionStore = null;

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

    /**
     * @param CacheItemPoolInterface $sessionPool the `mcp_sessions` cache pool
     *                                            (PSR-6, adapted for the SDK below)
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $sessionPool,
    ) {
    }

    public function build(): Server
    {
        $builder = Server::builder()
            ->setServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            ->setInstructions(implode("\n", [
                'Task management for task-loom (SPEC §10).',
                'Writes are gated: every task_create and task_update persists disabled and lands in the human approval queue (SPEC §4.3). You cannot create or enable tasks directly.',
                'Tasks may declare a step graph (SPEC §13): an array of levels; steps within a level run in parallel, levels run in sequence. Steps are optional — a task with no steps runs as a single unit.',
                'Read tools: task_list, task_get. Write tools: task_create, task_update.',
                'Review tools (SPEC §10): run_review digests what a run actually did (tool-call repetition, retries, errors, tokens) and run_read_log reads the ledger raw on request. Both are read-only; neither writes.',
            ]))
            ->setLogger($this->logger)
            // The container is what makes handler resolution work: TaskTools
            // and TaskCrud are services with constructor dependencies, and the
            // SDK resolves them through PSR-11 at call time.
            ->setContainer($this->container)
            ->setSession($this->sessionStore());

        $this->registerTaskTools($builder);
        $this->registerReviewTools($builder);

        return $builder->build();
    }

    /**
     * Mints and destroys sessions for callers that drive the server
     * in-process (the functional tests, which do not perform a handshake).
     */
    public function sessionManager(): SessionManager
    {
        return new SessionManager($this->sessionStore(), $this->logger);
    }

    /**
     * Backing store for MCP sessions.
     *
     * The SDK wants PSR-16 while a Symfony cache pool is PSR-6, so the pool is
     * adapted here rather than at every call site. The pool is a named one
     * (`mcp_sessions`) so sessions can be flushed or relocated to Redis
     * without disturbing the application cache.
     */
    public function sessionStore(): Psr16SessionStore
    {
        return $this->sessionStore ??= new Psr16SessionStore(
            cache: new Psr16Cache($this->sessionPool),
            prefix: 'mcp-session-',
            ttl: self::SESSION_TTL_SECONDS,
        );
    }

    private function registerTaskTools(Builder $builder): void
    {
        $builder
            ->addTool(
                handler: [TaskTools::class, 'create'],
                name: 'task_create',
                description: 'Create a new task. The task is persisted disabled and appears in the human approval queue — there is no way to create an enabled task through this tool (SPEC §4.3).',
                annotations: new ToolAnnotations(
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
                        'kind' => ['type' => 'string', 'enum' => ['run', 'session'], 'description' => 'Task kind. v1 ships "run" only — "session" is refused until its engine lands (docs/design/SESSION_TASKS.md).'],
                        'toolboxMode' => ['type' => 'string', 'enum' => ['tags', 'explicit'], 'description' => 'How the toolbox list is resolved at run time.'],
                        'toolbox' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags or explicit tool names, depending on toolboxMode.'],
                        'schedule' => ['type' => ['string', 'null'], 'description' => 'Optional cron-style schedule; v1 runs are manual, so leave null.'],
                        'steps' => self::stepsSchema('Optional step graph: an array of levels, each level an array of steps. Steps in a level run in parallel; levels run in sequence. Omit for a single-unit task.'),
                    ],
                    'required' => ['title', 'brief', 'kind', 'toolboxMode', 'toolbox'],
                ],
            )
            ->addTool(
                handler: [TaskTools::class, 'update'],
                name: 'task_update',
                description: 'Update a task. Enabled tasks are immutable (SPEC §4.4): updating one returns a disabled replacement draft; the original keeps running. Draft tasks are edited in place.',
                annotations: new ToolAnnotations(
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
                                'kind' => ['type' => 'string', 'enum' => ['run', 'session'], 'description' => 'Changing a task to "session" is refused until its engine lands.'],
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
            ->addTool(
                handler: [TaskTools::class, 'list'],
                name: 'task_list',
                description: 'List tasks, newest first. Read-only.',
                annotations: new ToolAnnotations(
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
            ->addTool(
                handler: [TaskTools::class, 'get'],
                name: 'task_get',
                description: "Get one task's full record, including its replacement chain. Read-only.",
                annotations: new ToolAnnotations(
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

    /**
     * The reviewer tools (SPEC §10). Every one is read-only: readOnlyHint is
     * true and no schema exposes a write. The gate that keeps proposals
     * human-approved (SPEC §4.3) is untouched by this pair — a reviewer that
     * wants to propose an edit calls task_update, which drafts.
     */
    private function registerReviewTools(Builder $builder): void
    {
        $builder
            ->addTool(
                handler: [RunReviewTools::class, 'review'],
                name: 'run_review',
                description: 'Digest the most recent settled run of a task: what it did, which tool calls repeated, what errored, what it cost, and the completion artifact. Deterministic (no LLM inside) and bounded by budgetChars. Use this first when reviewing a task for speed or reliability problems.',
                annotations: new ToolAnnotations(
                    title: 'Review a run',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'taskId' => ['type' => 'integer', 'description' => 'ID of the task to review.', 'minimum' => 1],
                        'runId' => ['type' => ['integer', 'null'], 'description' => 'Review this specific run instead of the newest settled one. Must belong to taskId.'],
                        'budgetChars' => [
                            'type' => 'integer',
                            'description' => 'Approximate cap on the digest, in characters. Sections that do not fit are listed under budget.elided rather than dropped silently.',
                            'minimum' => RunDigest::MIN_BUDGET,
                            'default' => RunDigest::DEFAULT_REVIEW_BUDGET,
                        ],
                        'history' => [
                            'type' => ['integer', 'null'],
                            'description' => 'Also include this run plus the settled runs before it as a trend (tool calls, repeats, errors, tokens). Use to answer "is this task getting worse?". Omit for the latest run only.',
                            'minimum' => 2,
                        ],
                    ],
                    'required' => ['taskId'],
                ],
            )
            ->addTool(
                handler: [RunReviewTools::class, 'readLog'],
                name: 'run_read_log',
                description: 'Read a run\'s attempt ledger raw: reasoning, tool arguments, tool results, errors, the prompt head, and the completion artifact. Defaults to the completion artifact only. The response always reports what did not fit under budget.elided — never conclude from a partial view without checking it.',
                annotations: new ToolAnnotations(
                    title: 'Read a run log',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'runId' => ['type' => 'integer', 'description' => 'ID of the run to read.', 'minimum' => 1],
                        'include' => [
                            'type' => 'array',
                            'description' => 'Entry kinds to include. Defaults to ["artifact"]. reasoning holds the model\'s thinking; omit it when only the outcome matters.',
                            'items' => ['type' => 'string', 'enum' => ['artifact', 'errors', 'tool_args', 'tool_results', 'thinking', 'prompt']],
                        ],
                        'budgetChars' => [
                            'type' => 'integer',
                            'description' => 'Character budget shared across the requested kinds. Entries are taken in ledger order; what does not fit is reported under budget.elided.',
                            'minimum' => RunDigest::MIN_BUDGET,
                            'default' => 8000,
                        ],
                        'entryChars' => [
                            'type' => 'integer',
                            'description' => 'Per-entry cap, in characters. Longer entries are truncated with an explicit marker.',
                            'minimum' => 200,
                            'default' => 4000,
                        ],
                    ],
                    'required' => ['runId'],
                ],
            );
    }
}
