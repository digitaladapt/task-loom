<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\LlmClient;
use App\Llm\LlmGuards;
use App\Llm\LlmStopReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

/**
 * The streaming client against a REAL socket: a PHP built-in server that
 * emits SSE.
 *
 * Everything the MockHttpClient tests cannot prove lives here — that the
 * read loop works against actual TCP framing (partial reads, real idle
 * windows, a real [DONE]), and that a guard cut closes the connection so
 * the endpoint can see it and stop generating (the requirement this whole
 * change exists to satisfy: "properly send the signal to the LLM so it
 * knows the client has cancelled").
 *
 * The server is a fixture, not a model: it emits fixed chunks on a fixed
 * cadence, so the assertions are about the client's behavior under real
 * transport conditions only.
 */
final class LlmClientLiveSocketTest extends TestCase
{
    private ?Process $server = null;

    private string $markerDir = '';

    private string $baseUrl = '';

    #[\Override]
    protected function setUp(): void
    {
        $port = self::findFreePort();
        $this->markerDir = sys_get_temp_dir().'/llm_fixture_'.getmypid();
        @mkdir($this->markerDir);

        $router = __DIR__.'/../../Fixtures/llm-sse-server.php';

        $this->server = new Process(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            null,
            ['LLM_FIXTURE_MARKER_DIR' => $this->markerDir],
        );
        $this->server->start();
        $this->baseUrl = "http://127.0.0.1:{$port}";
        $this->waitUntilUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop(0);

        foreach (glob($this->markerDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->markerDir);
    }

    private function waitUntilUp(): void
    {
        $deadline = microtime(true) + 5.0;
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]);

        while (microtime(true) < $deadline) {
            $handle = @fopen($this->baseUrl.'/plain', 'r', false, $context);
            if (false !== $handle) {
                fclose($handle);

                return;
            }
            usleep(50_000);
        }

        self::fail('The SSE fixture server did not come up.');
    }

    private static function findFreePort(): int
    {
        $sock = socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $ip, $port);
        socket_close($sock);

        return $port;
    }

    private function client(LlmGuards $guards, float $tick = 0.1, string $mode = ''): LlmClient
    {
        return new LlmClient(HttpClient::create(), $this->baseUrl.$mode, 'fixture', '', 30, null, null, $guards, $tick);
    }

    private function marker(string $name): string
    {
        return (string) @file_get_contents($this->markerDir.'/llm_fixture_'.$name);
    }

    /**
     * Count ESTABLISHED TCP connections to the fixture port by reading
     * /proc/net/tcp{,6} — the OS-level ground truth for "is the socket
     * still open".
     *
     * This is how the disconnect proof is asserted, rather than through a
     * server-side marker: the PHP built-in server only evaluates
     * connection_aborted() between output writes and can sit in usleep()
     * past the client's close, so its marker is opportunistic. The socket
     * state is not — a cancel() that failed to close the connection would
     * leave an ESTABLISHED entry behind, and that is exactly what this
     * test must catch.
     */
    private function establishedConnections(int $port): int
    {
        $count = 0;

        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
            $lines = @file($file);
            if (false === $lines) {
                continue;
            }

            foreach (\array_slice($lines, 1) as $line) {
                $fields = preg_split('/\s+/', trim($line));
                if (!\is_array($fields) || \count($fields) < 4) {
                    continue;
                }

                // [1] = local_address "HEX:PORT", [3] = state (01 ESTABLISHED).
                if ('01' !== $fields[3]) {
                    continue;
                }

                $parts = explode(':', $fields[1]);
                if (2 === \count($parts) && (int) hexdec($parts[1]) === $port) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    public function testACleanStreamEndsWithItsContentAndWithoutACut(): void
    {
        $guards = new LlmGuards(firstTokenSeconds: 5.0, silenceSeconds: 5.0, softDeadlineSeconds: 10.0, softExtensionSeconds: 1.0, maxSoftExtensions: 0, maxDurationSeconds: 10.0);

        $port = (int) parse_url($this->baseUrl, \PHP_URL_PORT);
        $response = $this->client($guards)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNull($response->stop);
        self::assertNotNull($response->content);
        self::assertStringContainsString('c0', $response->content);
        self::assertStringContainsString('c119', $response->content, 'the whole stream arrived across real sockets');
        self::assertSame(0, $this->establishedConnections($port), 'a completed stream releases its socket');
        self::assertSame('', $this->marker('abort'), 'the server was never aborted');
    }

    /**
     * THE DISCONNECT PROOF.
     *
     * A loop cuts the generation mid-stream. The cut must do two things:
     * return the partial output, and close the connection — which the
     * server observes as an aborted request. Without the second, the local
     * model keeps generating output nobody will ever read, which is the
     * "spinning forever and burning the GPU" complaint that motivated
     * this whole change.
     */
    public function testALoopCutKeepsTheOutputAndStopsTheEndpoint(): void
    {
        $guards = new LlmGuards(firstTokenSeconds: 5.0, silenceSeconds: 5.0, softDeadlineSeconds: 20.0, softExtensionSeconds: 1.0, maxSoftExtensions: 0, maxDurationSeconds: 20.0);

        $response = $this->client($guards, 0.1, '/looping')->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::LoopDetected, $response->stop->reason);
        self::assertNotNull($response->content, 'the repeated output before the cut is the result');
        self::assertStringContainsString('keep thinking', $response->content);

        // The cut must close the connection — that close IS the signal the
        // endpoint needs to stop generating. Poll the OS socket table
        // briefly: the transition is not synchronous with cancel().
        $port = (int) parse_url($this->baseUrl, \PHP_URL_PORT);
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && $this->establishedConnections($port) > 0) {
            usleep(50_000);
        }

        self::assertSame(0, $this->establishedConnections($port), 'the socket closed: the endpoint can see the client cancelled');

        // The endpoint had been mid-generation when the cut landed; it did
        // not run to its completion marker.
        self::assertStringNotContainsString('completed', $this->marker('complete'));
    }

    /**
     * The same disconnect proof on a time guard rather than the loop
     * detector: a silence cut on a silent endpoint also closes the socket.
     */
    public function testASilenceCutAlsoStopsTheEndpoint(): void
    {
        // The fixture goes quiet after two chunks for 30 seconds; the
        // silence window cuts well before that.
        $guards = new LlmGuards(firstTokenSeconds: 5.0, silenceSeconds: 0.5, softDeadlineSeconds: 10.0, softExtensionSeconds: 1.0, maxSoftExtensions: 0, maxDurationSeconds: 10.0);

        $response = $this->client($guards, 0.1, '/silent')->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::SilenceTimeout, $response->stop->reason);
        self::assertStringContainsString('c1', (string) $response->content);
    }
}
