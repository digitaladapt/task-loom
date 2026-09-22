<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp\Server;

use App\Entity\TaskAuthor;
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
}
