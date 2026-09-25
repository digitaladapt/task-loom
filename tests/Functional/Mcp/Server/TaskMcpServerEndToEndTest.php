<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp\Server;

use App\Entity\Step;
use App\Entity\TaskAuthor;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end: the task MCP server role over real Streamable HTTP —
 * JSON-RPC initialize + tools/list + tools/call against the actual
 * ReactPHP socket, exactly as an external agent would see it (SPEC §11).
 *
 * The gate assertion here is the whole point: a tools/call for task_create
 * must land in the DB as a disabled task (SPEC §4.3), and the write tools'
 * input schemas must contain no 'enabled' parameter.
 *
 * Server management notes: the port is picked dynamically (a fixed port
 * invites collisions with leaked processes from earlier runs), and the
 * readiness probe verifies the server's *identity* via initialize — a
 * mere "port accepts connections" check once passed against a stale
 * leftover process from an unrelated test.
 */
final class TaskMcpServerEndToEndTest extends KernelTestCase
{
    private static ?int $serverPid = null;
    private static int $port = 0;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $kernel = self::createKernel();
        $kernel->boot();

        self::$port = self::findFreePort();

        $cmd = sprintf(
            'APP_ENV=test php %s app:mcp:serve --host 127.0.0.1 --port %d --stateless > /tmp/mcp-serve-e2e.log 2>&1 & echo $!',
            escapeshellarg($kernel->getProjectDir().'/bin/console'),
            self::$port,
        );

        exec($cmd, $output, $exitCode);
        \assert(0 === $exitCode, 'server spawn failed: '.implode("\n", $output));
        self::$serverPid = (int) $output[0];

        // Readiness: initialize must answer with OUR server identity, not
        // just any listener that happens to hold the port.
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $probe = self::rpcStatic(self::$port, 'initialize', [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'e2e-probe', 'version' => '1.0.0'],
            ]);

            if (200 === $probe['code'] && 'task-loom' === ($probe['body']['result']['serverInfo']['name'] ?? null)) {
                return;
            }

            usleep(100_000);
        }

        self::fail('MCP server did not come up (or a foreign process holds the port): '.(file_get_contents('/tmp/mcp-serve-e2e.log') ?: ''));
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (null !== self::$serverPid) {
            exec(sprintf('kill %d 2>/dev/null', self::$serverPid));
            self::$serverPid = null;
        }
    }

    /**
     * Raw JSON-RPC POST, as any streamable-HTTP MCP client would do.
     *
     * @param array<string, mixed> $params
     *
     * @return array{code: int, body: array<string, mixed>}
     */
    private function rpc(string $method, array $params): array
    {
        return self::rpcStatic(self::$port, $method, $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{code: int, body: array<string, mixed>}
     */
    private static function rpcStatic(int $port, string $method, array $params): array
    {
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => random_int(1, 2 ** 30), 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);

        $ch = curl_init("http://127.0.0.1:{$port}/mcp");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [
            'code' => $code,
            'body' => json_decode((string) $body, true) ?? [],
        ];
    }

    private static function findFreePort(): int
    {
        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        \socket_bind($sock, '127.0.0.1', 0);
        \socket_getsockname($sock, $ip, $port);
        \socket_close($sock);

        return $port;
    }

    public function testInitializeExposesServerIdentity(): void
    {
        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'e2e-test', 'version' => '1.0.0'],
        ]);
        self::assertSame(200, $init['code'], 'initialize failed: '.json_encode($init['body']));
        self::assertSame('task-loom', $init['body']['result']['serverInfo']['name']);
    }

    public function testToolsListExposesFourTaskTools(): void
    {
        $list = $this->rpc('tools/list', []);
        self::assertSame(200, $list['code']);
        $names = array_map(static fn (array $t): string => $t['name'], $list['body']['result']['tools']);
        sort($names);
        self::assertSame(['task_create', 'task_get', 'task_list', 'task_update'], $names);
    }

    public function testToolsListWriteSchemasHaveNoEnabledParameter(): void
    {
        $list = $this->rpc('tools/list', []);
        $tools = $list['body']['result']['tools'];

        foreach ($tools as $tool) {
            $props = $tool['inputSchema']['properties'] ?? [];
            self::assertArrayNotHasKey(
                'enabled',
                $props,
                "Tool {$tool['name']} exposes an 'enabled' parameter — the gate must not be reachable (SPEC §4.3).",
            );
        }
    }

    public function testToolsCallTaskCreatePersistsDisabled(): void
    {
        $response = $this->rpc('tools/call', [
            'name' => 'task_create',
            'arguments' => [
                'title' => 'E2E Gated Task',
                'brief' => 'Created over real MCP.',
                'kind' => 'run',
                'toolboxMode' => 'tags',
                'toolbox' => ['weather'],
            ],
        ]);

        self::assertSame(200, $response['code'], 'tools/call failed: '.json_encode($response['body']));
        self::assertArrayNotHasKey('error', $response['body'], json_encode($response['body']));
        $text = $response['body']['result']['content'][0]['text'] ?? '';
        $result = json_decode($text, true);
        self::assertIsArray($result, 'tool result is not JSON: '.$text);
        $taskId = $result['id'];

        // The gate proof: the persisted row is disabled and agent-authored.
        self::bootKernel();
        $tasks = static::getContainer()->get(TaskRepository::class);
        $task = $tasks->find($taskId);

        self::assertNotNull($task, 'task_create did not persist a row');
        self::assertFalse($task->isEnabled(), 'SPEC §4.3 gate breached: agent write persisted enabled');
        self::assertSame(TaskAuthor::Agent, $task->getCreatedBy());
        self::assertSame('E2E Gated Task', $task->getTitle());
    }

    public function testToolsCallInvalidArgumentsReturnsStructuredError(): void
    {
        $response = $this->rpc('tools/call', [
            'name' => 'task_create',
            'arguments' => [
                'title' => '', // minLength 1
                'brief' => 'x',
                'kind' => 'run',
                'toolboxMode' => 'tags',
                'toolbox' => [],
            ],
        ]);

        // Validation failure must be a JSON-RPC error, not a 500.
        self::assertSame(200, $response['code']);
        self::assertArrayHasKey('error', $response['body']);
        self::assertSame(-32602, $response['body']['error']['code']);
    }

    public function testToolsCallTaskCreateWithStepsPersistsGraphAndRendersItBack(): void
    {
        $steps = [
            [
                ['title' => 'Weather', 'brief' => 'Fetch the weather.', 'toolbox_mode' => 'explicit', 'toolbox' => ['echo']],
                ['title' => 'Calendar', 'brief' => 'Fetch the calendar.'],
            ],
            [
                ['title' => 'Summary', 'brief' => 'Summarize all inputs.'],
            ],
        ];

        $created = $this->callTool('task_create', [
            'title' => 'E2E Stepped Task',
            'brief' => 'Compose the briefing.',
            'kind' => 'run',
            'toolboxMode' => 'tags',
            'toolbox' => ['weather'],
            'steps' => $steps,
        ]);

        self::assertFalse($created['error'], $created['raw']);
        $taskId = $created['result']['id'];

        // The stored edges: summary depends on both level-1 steps.
        $this->bootKernel();
        $tasks = static::getContainer()->get(TaskRepository::class);
        $task = $tasks->find($taskId);
        self::assertNotNull($task);
        self::assertFalse($task->isEnabled(), 'SPEC §4.3 gate holds for stepped creates');

        $stepsRepo = static::getContainer()->get(StepRepository::class);
        $stored = $stepsRepo->findForTask($task);
        self::assertCount(3, $stored);
        self::assertSame([], $stored[0]->getDependsOn());
        self::assertSame([], $stored[1]->getDependsOn());
        self::assertSame([$stored[0]->getId(), $stored[1]->getId()], $stored[2]->getDependsOn());
        self::assertSame('Weather', $stored[0]->getTitle());
        self::assertSame(['echo'], $stored[0]->getToolbox());

        // task_get renders the graph back in the authoring format
        // (defaults filled in: toolbox_mode "explicit", toolbox []).
        $got = $this->callTool('task_get', ['taskId' => $taskId]);
        self::assertFalse($got['error'], $got['raw']);
        $normalized = array_map(fn (array $level) => array_map(fn (array $s) => [
            'title' => $s['title'],
            'brief' => $s['brief'],
            'toolbox_mode' => $s['toolbox_mode'] ?? 'explicit',
            'toolbox' => $s['toolbox'] ?? [],
        ], $level), $steps);
        self::assertSame($normalized, $got['result']['steps']);
    }

    public function testToolsCallTaskUpdateReplacesStepGraph(): void
    {
        $created = $this->callTool('task_create', [
            'title' => 'E2E Update Steps',
            'brief' => 'B.',
            'kind' => 'run',
            'toolboxMode' => 'tags',
            'toolbox' => [],
            'steps' => [[['title' => 'Old', 'brief' => 'old.']]],
        ]);
        self::assertFalse($created['error'], $created['raw']);
        $taskId = $created['result']['id'];

        $updated = $this->callTool('task_update', [
            'taskId' => $taskId,
            'changes' => ['steps' => [
                [['title' => 'New A', 'brief' => 'a.']],
                [['title' => 'New B', 'brief' => 'b.']],
            ]],
        ]);
        self::assertFalse($updated['error'], $updated['raw']);
        self::assertSame('draft', $updated['result']['status']);
        self::assertSame(2, $updated['result']['steps']);

        $this->bootKernel();
        $tasks = static::getContainer()->get(TaskRepository::class);
        $task = $tasks->find($taskId);
        self::assertNotNull($task);

        $stored = static::getContainer()->get(StepRepository::class)->findForTask($task);
        self::assertSame(['New A', 'New B'], array_map(static fn (Step $s) => $s->getTitle(), $stored));
        self::assertSame([$stored[0]->getId()], $stored[1]->getDependsOn());
    }

    public function testToolsCallTaskUpdateWithStringEnumsAndNoStepsKey(): void
    {
        // Regression: `changes` values arrive as JSON strings; the
        // persistence layer must coerce them (TypeError before).
        $created = $this->callTool('task_create', [
            'title' => 'E2E Enum Coercion',
            'brief' => 'B.',
            'kind' => 'run',
            'toolboxMode' => 'tags',
            'toolbox' => ['weather'],
        ]);
        self::assertFalse($created['error'], $created['raw']);
        $taskId = $created['result']['id'];

        $updated = $this->callTool('task_update', [
            'taskId' => $taskId,
            'changes' => ['toolbox_mode' => 'explicit', 'toolbox' => ['echo']],
        ]);

        self::assertFalse($updated['error'], $updated['raw']);

        $this->bootKernel();
        $task = static::getContainer()->get(TaskRepository::class)->find($taskId);
        self::assertNotNull($task);
        self::assertSame('explicit', $task->getToolboxMode()->value);
        self::assertSame(['echo'], $task->getToolbox());
    }

    public function testToolsCallTaskCreateWithMalformedStepsSurfacesDiagnosis(): void
    {
        // Whitespace-only strings pass the coarse JSON schema (length ≥ 1)
        // but fail the codec's trim-and-require check server-side — the
        // precise layer's indexed diagnosis must reach the caller.
        $response = $this->rpc('tools/call', [
            'name' => 'task_create',
            'arguments' => [
                'title' => 'Malformed steps',
                'brief' => 'B.',
                'kind' => 'run',
                'toolboxMode' => 'tags',
                'toolbox' => [],
                'steps' => [[['title' => '   ', 'brief' => 'x', 'toolbox' => ['  ']]]],
            ],
        ]);

        self::assertSame(200, $response['code']);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result, json_encode($response['body']));
        self::assertTrue($result['isError'], 'malformed steps must surface as a tool error, not silence');

        $text = $result['content'][0]['text'] ?? '';
        self::assertStringContainsString('steps[0][0].title', $text);
        self::assertStringContainsString('steps[0][0].toolbox[0]', $text);
    }

    public function testToolsCallTaskCreateWithSchemaViolatingStepsIsRejectedByTheSchemaGate(): void
    {
        // The coarse gate: structurally invalid steps (empty title,
        // unknown field) never reach the persistence layer.
        $response = $this->rpc('tools/call', [
            'name' => 'task_create',
            'arguments' => [
                'title' => 'Schema-violating steps',
                'brief' => 'B.',
                'kind' => 'run',
                'toolboxMode' => 'tags',
                'toolbox' => [],
                'steps' => [[['title' => '', 'brief' => 'x', 'bogus_field' => true]]],
            ],
        ]);

        self::assertSame(200, $response['code']);
        self::assertArrayHasKey('error', $response['body']);
        self::assertSame(-32602, $response['body']['error']['code']);
    }

    public function testToolsCallTaskUpdateOnEnabledTaskReportsReplacementDraft(): void
    {
        $created = $this->callTool('task_create', [
            'title' => 'E2E Replacement Status',
            'brief' => 'B.',
            'kind' => 'run',
            'toolboxMode' => 'tags',
            'toolbox' => ['weather'],
            'steps' => [[['title' => 'Keep', 'brief' => 'k.']]],
        ]);
        self::assertFalse($created['error'], $created['raw']);
        $taskId = $created['result']['id'];

        // Enable the task out of band (the human gate is the admin UI).
        $this->bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        $task = static::getContainer()->get(TaskRepository::class)->find($taskId);
        self::assertNotNull($task);
        $task->enable();
        $em->flush();

        $updated = $this->callTool('task_update', [
            'taskId' => $taskId,
            'changes' => ['brief' => 'Tighter.'],
        ]);

        self::assertFalse($updated['error'], $updated['raw']);
        // isDraft() is true for replacement drafts too; the status must
        // say what actually happened.
        self::assertSame('replacement_draft', $updated['result']['status']);
        self::assertSame($taskId, $updated['result']['replacement_for']);
        self::assertSame(1, $updated['result']['steps'], 'the replacement cloned the graph');
    }

    /**
     * One tools/call returning the decoded tool payload plus the raw text.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{error: bool, result: array<string, mixed>, raw: string}
     */
    private function callTool(string $name, array $arguments): array
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
        self::assertSame(200, $response['code'], json_encode($response['body']));

        $contents = $response['body']['result']['content'] ?? [];
        $text = $contents[0]['text'] ?? '';
        $decoded = json_decode($text, true);

        return [
            'error' => (bool) ($response['body']['result']['isError'] ?? true),
            'result' => \is_array($decoded) ? $decoded : [],
            'raw' => $text,
        ];
    }
}
