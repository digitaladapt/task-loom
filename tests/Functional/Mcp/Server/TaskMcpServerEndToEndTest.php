<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp\Server;

use App\Entity\TaskAuthor;
use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end: the task MCP server role over real Streamable HTTP (SPEC §11) —
 * JSON-RPC initialize + tools/list + tools/call, exactly as an external agent
 * would see it.
 *
 * The gate assertion is the whole point: a tools/call for task_create must
 * persist a disabled task (SPEC §4.3), and the write tools' input schemas must
 * contain no 'enabled' parameter.
 *
 * This suite used to spawn `app:mcp:serve` as a background process, pick a free
 * port, poll for readiness and kill a PID — its own docblock warned that a
 * fixed port "invites collisions with leaked processes from earlier runs" and
 * that a plain "port accepts connections" probe once passed against a stale
 * leftover process. With the MCP endpoint served from the app (see
 * docs/design/MCP_SDK_MIGRATION.md) the same wire traffic goes through the
 * kernel, so the process choreography, the port hunt and the readiness polling
 * are all gone.
 *
 * Sessions: the SDK requires an established session for every request other
 * than `initialize`, answering anything else with 400. That is the spec
 * behaving correctly, so this suite performs a handshake first — exactly as a
 * real client must.
 */
final class TaskMcpServerEndToEndTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        // The MCP endpoint is admin-guarded like every other app route
        // (config/packages/security.yaml ends with `^/ → ROLE_ADMIN`).
        $this->client->setServerParameter('PHP_AUTH_USER', 'admin');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test-admin-password');

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->flush();
        $em->clear();
    }

    /**
     * Perform the MCP handshake and return the session id later requests need.
     */
    private function initializeSession(): string
    {
        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'e2e-test', 'version' => '1.0.0'],
        ]);

        self::assertSame(200, $init['code'], 'initialize failed: '.json_encode($init['body']));

        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId, 'initialize must return an Mcp-Session-Id header.');

        return $sessionId;
    }

    /**
     * Raw JSON-RPC POST inside an established session, as any Streamable HTTP
     * MCP client would do.
     *
     * @param array<string, mixed> $params
     *
     * @return array{code: int, body: array<string, mixed>}
     */
    private function rpc(string $method, array $params, ?string $sessionId = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->client->request('POST', '/mcp', server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => random_int(1, 2 ** 30),
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();

        return [
            'code' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
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
        $sessionId = $this->initializeSession();
        $list = $this->rpc('tools/list', [], $sessionId);

        self::assertSame(200, $list['code']);
        $names = array_map(static fn (array $t): string => $t['name'], $list['body']['result']['tools']);
        sort($names);
        self::assertSame(['task_create', 'task_get', 'task_list', 'task_update'], $names);
    }

    public function testToolsListWriteSchemasHaveNoEnabledParameter(): void
    {
        $sessionId = $this->initializeSession();
        $list = $this->rpc('tools/list', [], $sessionId);

        $tools = $list['body']['result']['tools'];
        self::assertCount(4, $tools);

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
        $sessionId = $this->initializeSession();
        $response = $this->rpc('tools/call', [
            'name' => 'task_create',
            'arguments' => [
                'title' => 'E2E Gated Task',
                'brief' => 'Created over real MCP.',
                'kind' => 'run',
                'toolboxMode' => 'tags',
                'toolbox' => ['weather'],
            ],
        ], $sessionId);

        self::assertSame(200, $response['code'], 'tools/call failed: '.json_encode($response['body']));
        self::assertArrayNotHasKey('error', $response['body'], json_encode($response['body']));
        $text = $response['body']['result']['content'][0]['text'] ?? '';
        $result = json_decode($text, true);
        self::assertIsArray($result, 'tool result is not JSON: '.$text);
        $taskId = $result['id'];

        // The gate proof: the persisted row is disabled and agent-authored.
        static::ensureKernelShutdown();
        self::bootKernel();
        $task = static::getContainer()->get(TaskRepository::class)->find($taskId);

        self::assertNotNull($task, 'task_create did not persist a row');
        self::assertFalse($task->isEnabled(), 'SPEC §4.3 gate breached: agent write persisted enabled');
        self::assertSame(TaskAuthor::Agent, $task->getCreatedBy());
        self::assertSame('E2E Gated Task', $task->getTitle());
    }

    public function testToolsCallInvalidArgumentsReturnsStructuredError(): void
    {
        $sessionId = $this->initializeSession();
        $response = $this->rpc('tools/call', [
            'name' => 'task_create',
            'arguments' => [
                'title' => '', // minLength 1
                'brief' => 'x',
                'kind' => 'run',
                'toolboxMode' => 'tags',
                'toolbox' => [],
            ],
        ], $sessionId);

        // Validation failure must be a JSON-RPC error, not a 500.
        self::assertSame(200, $response['code']);
        self::assertArrayHasKey('error', $response['body']);
        self::assertSame(-32602, $response['body']['error']['code']);
    }

    public function testToolsCallWithoutSessionIsRejected(): void
    {
        // Deliberate behaviour change from `app:mcp:serve --stateless`: the SDK
        // requires a real handshake, so a caller that skips it is told so
        // rather than silently served.
        $response = $this->rpc('tools/list', []);

        self::assertSame(400, $response['code']);
        self::assertSame(-32600, $response['body']['error']['code']);
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        // The endpoint inherits the app's admin guard (no access_control
        // exemption), so reaching it requires credentials.
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->setServerParameter('PHP_AUTH_USER', '');
        $client->setServerParameter('PHP_AUTH_PW', '');

        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
