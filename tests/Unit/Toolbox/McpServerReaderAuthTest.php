<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Toolbox\CredentialResolutionException;
use App\Toolbox\McpServerReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof of the credential path through the real reader against a
 * real server that REQUIRES an Authorization header.
 *
 * This is the regression test for the reported bug: `cred_var` named an env
 * var that nothing ever read, so the sync sent no header and a secured server
 * answered 401. Here the server rejects anything without
 * `Bearer <EXPECTED_MCP_TOKEN>`, and the reader discovers its tools — which
 * can only happen if the env var was resolved and converted to the header.
 */
final class McpServerReaderAuthTest extends TestCase
{
    private const TOKEN = 'test-mcp-token-7f3a9';
    private const ENV_NAME = 'TASKLOOM_TEST_AUTHED_MCP_TOKEN';

    private ?Process $server = null;
    private string $baseUrl = '';

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

        // The fixture checks the token against its OWN environment; pass it
        // explicitly so the value never depends on the ambient shell.
        $this->server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            env: ['EXPECTED_MCP_TOKEN' => self::TOKEN],
        );
        $this->server->start();

        // The fixture 401s (and 405s on GET) until a valid header arrives, so
        // the probe must accept any HTTP status — only a connection failure
        // means "not up yet".
        $this->baseUrl = "http://127.0.0.1:{$port}/mcp";
        $this->waitUntilUp();
    }

    private function waitUntilUp(): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $handle = @fopen($this->baseUrl, 'r', false, $context);
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

    public function testSyncResolvesCredVarToAuthorizationHeader(): void
    {
        $this->setEnv(self::TOKEN);
        $this->startServer();

        $reader = new McpServerReader(5);
        $server = new McpServer('secured', $this->baseUrl, ServerProtocol::Mcp, self::ENV_NAME);

        $tools = $reader->read($server);

        self::assertCount(1, $tools);
        self::assertSame('secret_tool', $tools[0]->name);
    }

    public function testSyncWithoutCredVarIsRejectedByTheServer(): void
    {
        // The same server, but the row declares no credential → no header →
        // the 401 the bug report described. Proves the header is what makes
        // the difference (not some server-side permissiveness).
        $this->startServer();

        $reader = new McpServerReader(5);
        $server = new McpServer('secured', $this->baseUrl, ServerProtocol::Mcp);

        $this->expectException(\Throwable::class);

        $reader->read($server);
    }

    public function testMissingEnvVarFailsBeforeContactingTheServer(): void
    {
        // A server that is not even running: if the credential resolution
        // failed *after* connecting we could not tell the two apart. We
        // expect the credential exception, not a connection error.
        $reader = new McpServerReader(5);
        $server = new McpServer('secured', 'http://127.0.0.1:1/mcp', ServerProtocol::Mcp, self::ENV_NAME);

        $this->expectException(CredentialResolutionException::class);

        $reader->read($server);
    }
}
