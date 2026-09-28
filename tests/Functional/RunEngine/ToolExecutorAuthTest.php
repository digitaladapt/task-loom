<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\RunEngine\ToolExecutionException;
use App\RunEngine\ToolExecutor;
use App\Toolbox\CredentialResolutionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The runtime half of the credential fix: a tool CALL (not just a catalog
 * sync) must carry the server's resolved Authorization header.
 *
 * `ToolExecutor` builds its transport from the run's frozen toolbox snapshot,
 * so this also implicitly covers the snapshot carrying `credVar` — the
 * `Tool`/`McpServer` pair below is built exactly the way
 * `ToolboxSnapshot::toTools()` rebuilds it.
 */
final class ToolExecutorAuthTest extends TestCase
{
    private const TOKEN = 'runtime-token-1b2c3';
    private const ENV_NAME = 'TASKLOOM_TEST_RUNTIME_MCP_TOKEN';

    private ?Process $server = null;
    private string $endpoint = '';

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop(0);
        unset($_ENV[self::ENV_NAME], $_SERVER[self::ENV_NAME]);
        putenv(self::ENV_NAME);

        parent::tearDown();
    }

    private function setEnv(string $value): void
    {
        $_ENV[self::ENV_NAME] = $value;
        $_SERVER[self::ENV_NAME] = $value;
        putenv(self::ENV_NAME.'='.$value);
    }

    private function startServer(): void
    {
        $port = self::findFreePort();
        $router = __DIR__.'/../../Fixtures/authed-streamable-mcp-server.php';

        $this->server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            env: ['EXPECTED_MCP_TOKEN' => self::TOKEN],
        );
        $this->server->start();

        $this->endpoint = "http://127.0.0.1:{$port}/mcp";
        $this->waitUntilUp();
    }

    private function waitUntilUp(): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $handle = @fopen($this->endpoint, 'r', false, $context);
            if (false !== $handle) {
                fclose($handle);

                return;
            }
            usleep(50_000);
        }

        self::fail('Authed test MCP server did not come up.');
    }

    private static function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $ip, $port);
        socket_close($sock);

        return $port;
    }

    private function tool(?string $credVar): Tool
    {
        $server = new McpServer('secured', $this->endpoint, ServerProtocol::Mcp, $credVar);

        return new Tool($server, 'secret_tool', 'Only callable with a credential.', ['type' => 'object']);
    }

    public function testExecuteResolvesCredVarToAuthorizationHeader(): void
    {
        $this->setEnv(self::TOKEN);
        $this->startServer();

        $executor = new ToolExecutor(10);
        $result = $executor->execute($this->tool(self::ENV_NAME), ['message' => 'hi']);

        self::assertFalse($result['isError']);
        self::assertSame('authed:{"message":"hi"}', $result['content']);
    }

    public function testExecuteWithoutCredVarIsRejectedByTheServer(): void
    {
        $this->startServer();

        $executor = new ToolExecutor(10);

        // No credential → no header → the server 401s → a classified
        // server_error, never a silent path (SPEC §5.1).
        $this->expectException(ToolExecutionException::class);

        $executor->execute($this->tool(null), ['message' => 'hi']);
    }

    public function testMissingEnvVarFailsTheCallWithTheEnvVarNamed(): void
    {
        // No server started: a credential fault must be distinguishable from
        // an unreachable server, and the message must name the env var only.
        $executor = new ToolExecutor(5);

        try {
            $executor->execute($this->tool(self::ENV_NAME), []);
            self::fail('Expected a ToolExecutionException.');
        } catch (ToolExecutionException $e) {
            self::assertStringContainsString(self::ENV_NAME, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertInstanceOf(CredentialResolutionException::class, $e->getPrevious());
        }
    }
}
