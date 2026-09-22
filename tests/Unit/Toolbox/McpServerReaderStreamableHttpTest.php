<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Toolbox\McpServerReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression test for the MCP 405 crash against Streamable HTTP servers.
 *
 * The SDK's built-in Http transport GETs the endpoint and waits for an SSE
 * stream (legacy HTTP+SSE protocol). Streamable HTTP servers — including
 * context-shuttle — answer that GET with 405, which the SDK treated as a
 * fatal ConnectionException. The reader now installs a StreamableHttpTransport
 * via a custom TransportFactory.
 *
 * Spins up a local PHP built-in server (loopback, ephemeral port) that
 * implements Streamable HTTP behavior: 405 on GET, JSON-RPC on POST, in
 * both application/json and text/event-stream response flavors.
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

    public function testReadDiscoversToolsFromStreamableHttpServer(): void
    {
        $this->startServer('/mcp');

        $reader = new McpServerReader(5);
        $server = new McpServer('shuttle', $this->baseUrl, ServerProtocol::Mcp);

        $tools = $reader->read($server);

        self::assertCount(1, $tools);
        self::assertSame('echo', $tools[0]->name);
        self::assertSame('Echo the message back.', $tools[0]->description);
    }

    public function testReadDiscoversToolsFromSseFramedResponses(): void
    {
        $this->startServer('/sse-mcp');

        $reader = new McpServerReader(5);
        $server = new McpServer('shuttle', $this->baseUrl, ServerProtocol::Mcp);

        $tools = $reader->read($server);

        self::assertCount(1, $tools);
        self::assertSame('echo', $tools[0]->name);
    }
}
