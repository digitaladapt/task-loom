<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Toolbox\McpServerReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression test for the MCP 405 crash against Streamable HTTP servers — kept,
 * but reframed.
 *
 * The bug: php-mcp/client's built-in HTTP transport opened a legacy HTTP+SSE
 * stream (a GET) and treated Streamable HTTP servers' 405 as a fatal
 * ConnectionException. The fix at the time was a hand-rolled transport in
 * src/Toolbox/Transport/, installed via a custom TransportFactory.
 *
 * The official SDK's transport POSTs, handles both JSON and SSE response
 * framing, and manages the session header itself — so the workaround is gone.
 * This test is now the *proof that the workaround is unnecessary* rather than
 * the proof that one exists: it drives the real reader against a real server
 * that answers GET with 405, and asserts the tools come back.
 *
 * If a future SDK release reintroduces a GET-first handshake, these tests fail
 * loudly instead of the catalog quietly emptying.
 */
final class McpServerReaderStreamableHttpTest extends TestCase
{
    private ?Process $server = null;
    private string $baseUrl = '';

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop(0);
        parent::tearDown();
    }

    private function startServer(string $path): void
    {
        $port = self::findFreePort();
        $router = __DIR__.'/../../Fixtures/streamable-mcp-server.php';

        $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router]);
        $this->server->start();
        $this->waitUntilUp("http://127.0.0.1:{$port}{$path}");
        $this->baseUrl = "http://127.0.0.1:{$port}{$path}";
    }

    private function waitUntilUp(string $url): void
    {
        // The fixture 405s on GET by design, so the probe must accept any
        // HTTP status — only a connection failure means "not up yet".
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $handle = @fopen($url, 'r', false, $context);
            if (false !== $handle) {
                fclose($handle);

                return;
            }
            usleep(50_000);
        }

        self::fail('Test MCP server did not come up.');
    }

    private static function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $ip, $port);
        socket_close($sock);

        return $port;
    }

    private function assertDiscoversEchoTool(string $path): void
    {
        $this->startServer($path);

        $reader = new McpServerReader(5);
        $server = new McpServer('shuttle', $this->baseUrl, ServerProtocol::Mcp);

        $tools = $reader->read($server);

        self::assertCount(1, $tools);
        self::assertSame('echo', $tools[0]->name);
        self::assertSame('Echo the message back.', $tools[0]->description);
        self::assertSame(
            ['type' => 'object', 'properties' => ['message' => ['type' => 'string']], 'required' => ['message']],
            $tools[0]->schema,
        );
    }

    public function testReadDiscoversToolsFromStreamableHttpServer(): void
    {
        $this->assertDiscoversEchoTool('/mcp');
    }

    public function testReadDiscoversToolsFromSseFramedResponses(): void
    {
        $this->assertDiscoversEchoTool('/sse-mcp');
    }
}
