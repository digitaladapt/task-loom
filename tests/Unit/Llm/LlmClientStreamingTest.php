<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\LlmClient;
use App\Llm\LlmGuards;
use App\Llm\LlmRequestException;
use App\Llm\LlmStopReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The streaming client against in-process MockHttpClient bodies.
 *
 * MockResponse's iterable body is the seam that makes every guard testable
 * without a socket: yielding a string is a data chunk (one SSE delivery),
 * yielding '' simulates an idle timeout (the session ends and the client
 * resumes — exactly what a real quiet socket does), and throwing yields a
 * transport error chunk. The time-based guards are driven with sub-second
 * windows and real (small) sleeps, so the wall-clock reasoning under test
 * is the production reasoning, not a mock of it.
 */
final class LlmClientStreamingTest extends TestCase
{
    /**
     * Wraps a MockHttpClient so tests can inspect the response the client
     * actually used — the only way to assert cancel() fired, which is the
     * "tell the endpoint to stop" behavior the whole design rests on.
     */
    private function recordingClient(MockHttpClient $http): RecordingHttpClient
    {
        return new RecordingHttpClient($http);
    }

    /**
     * @param list<array{int, string}> $script
     */
    private function scriptedClient(array $script): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new MockResponse('', ['http_code' => 200]), $script);
    }

    /**
     * One SSE frame for a content delta.
     */
    private function contentEvent(string $text): string
    {
        return 'data: '.json_encode(['choices' => [['delta' => ['content' => $text]]]], \JSON_UNESCAPED_SLASHES)."\n\n";
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function guards(array $overrides = []): LlmGuards
    {
        $defaults = [
            'firstTokenSeconds' => 5.0,
            'silenceSeconds' => 5.0,
            'softDeadlineSeconds' => 5.0,
            'softExtensionSeconds' => 1.0,
            'maxSoftExtensions' => 0,
            'maxDurationSeconds' => 5.0,
        ];

        return new LlmGuards(...array_merge($defaults, $overrides));
    }

    private function makeClient(HttpClientInterface $http, ?LlmGuards $guards = null): LlmClient
    {
        return new LlmClient(
            $http,
            'https://llm.example',
            'test-model',
            'secret-key',
            30,
            null,
            null,
            $guards ?? $this->guards(),
            0.05,
        );
    }

    public function testAStreamedCompletionIsAssembledWithUsageAndMetrics(): void
    {
        $body = (static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"content":"Hel"}}]}'."\n\n";
            yield 'data: {"choices":[{"delta":{"content":"lo"}}]}'."\n\n";
            yield 'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"total_tokens":7}}'."\n\n";
            yield "data: [DONE]\n\n";
        })();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'ping']]);

        self::assertSame('Hello', $response->content);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(['total_tokens' => 7], $response->usage);
        self::assertNull($response->stop);
        self::assertNotNull($response->metrics);
        self::assertSame(2, $response->metrics->chunks);
        self::assertNotNull($response->metrics->firstDeltaMs);
        self::assertNotNull($response->metrics->firstContentMs);
    }

    public function testToolCallArgumentsSplitAcrossDeltasAreReassembled(): void
    {
        $body = (static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"get_weather"}}]}}]}'."\n\n";
            foreach (['{"loc', 'ation":', '"Reykjavik"}'] as $fragment) {
                yield 'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => $fragment]]]]]]])."\n\n";
            }
            yield "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n";
            yield "data: [DONE]\n\n";
        })();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'weather?']]);

        self::assertTrue($response->wantsToolCall());
        self::assertSame([[
            'id' => 'call_1',
            'name' => 'get_weather',
            'arguments' => ['location' => 'Reykjavik'],
        ]], $response->getToolCalls());
    }

    public function testANonSseBodyFallsBackToTheOldParser(): void
    {
        // An endpoint that ignored stream:true and sent one JSON body.
        $json = json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'plain pong']]],
            'usage' => ['total_tokens' => 3],
        ]);

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) $json, ['http_code' => 200]));
        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'ping']]);

        self::assertSame('plain pong', $response->content);
        self::assertSame('stop', $response->finishReason);
    }

    public function testA401IsClassifiedAsAnLlmErrorWithItsMessage(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"error":{"message":"Invalid API Key"}}',
            ['http_code' => 401],
        ));

        try {
            $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);
            self::fail('LlmRequestException was not thrown');
        } catch (LlmRequestException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('Invalid API Key', $e->getMessage());
        }
    }

    public function testAFirstTokenTimeoutWithNoOutputThrowsItsOwnClass(): void
    {
        // A quiet socket from the first byte: nothing is ever produced and
        // the wall clock passes the first-token window.
        $script = [];
        for ($i = 0; $i < 12; ++$i) {
            $script[] = [40_000, ''];
        }

        try {
            $this->makeClient($this->scriptedClient($script), $this->guards(['firstTokenSeconds' => 0.15, 'silenceSeconds' => 5.0, 'softDeadlineSeconds' => 5.0, 'maxDurationSeconds' => 5.0]))->chat([['role' => 'user', 'content' => 'x']]);
            self::fail('expected a first-token stop');
        } catch (LlmRequestException $e) {
            self::assertSame(LlmStopReason::FirstTokenTimeout->errorClass(), $e->errorClass);
            self::assertStringContainsString('FIRST_TOKEN', $e->getMessage());
        }
    }

    public function testASilenceTimeoutKeepsThePartialOutputAndRecordsWhyItEnded(): void
    {
        // Output flows once, then the endpoint goes quiet: the exact
        // "wedged mid-stream" shape the silence window exists for.
        $script = [[0, $this->contentEvent('part of an answer')]];
        for ($i = 0; $i < 12; ++$i) {
            $script[] = [60_000, ''];
        }

        $response = $this->makeClient($this->scriptedClient($script), $this->guards(['firstTokenSeconds' => 5.0, 'silenceSeconds' => 0.2]))->chat([['role' => 'user', 'content' => 'x']]);

        self::assertSame('part of an answer', $response->content, 'partial output is output');
        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::SilenceTimeout, $response->stop->reason);
        self::assertStringContainsString('SILENCE', $response->stop->detail);
    }

    public function testASoftDeadlineCutAfterExtensionsKeepsThePartialOutput(): void
    {
        // Output keeps flowing — the busy-model case — and the extensions
        // run out. The cut lands mid-flow with everything captured.
        $script = [];
        for ($i = 0; $i < 20; ++$i) {
            $script[] = [30_000, $this->contentEvent('tok '.$i.' ')];
        }

        $response = $this->makeClient($this->scriptedClient($script), $this->guards([
            'firstTokenSeconds' => 5.0,
            'silenceSeconds' => 5.0,
            'softDeadlineSeconds' => 0.12,
            'softExtensionSeconds' => 0.06,
            'maxSoftExtensions' => 1,
            'maxDurationSeconds' => 0.18,
        ]))->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::DurationExceeded, $response->stop->reason);
        self::assertStringContainsString('extension', $response->stop->detail);
        self::assertNotNull($response->metrics);
        self::assertSame(1, $response->metrics->extensions, 'one extension was granted before the cut');
        self::assertNotNull($response->content);
        self::assertStringContainsString('tok 0', $response->content);
    }

    public function testALoopIsCutAndTheRepetitionIsNamed(): void
    {
        $body = (static function (): \Generator {
            for ($i = 0; $i < 40; ++$i) {
                yield 'data: {"choices":[{"delta":{"content":"I will keep thinking about this. "}}]}'."\n\n";
            }
        })();

        $recorder = $this->recordingClient(new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));
        $response = $this->makeClient($recorder)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::LoopDetected, $response->stop->reason);
        self::assertStringContainsString('thinking about this', $response->stop->detail, 'the evidence names what repeated');
        self::assertNotNull($response->content);
        self::assertStringContainsString('I will keep thinking', $response->content, 'what was produced before the cut is kept');

        // The endpoint must be told to stop: the socket is closed.
        self::assertNotNull($recorder->last);
        self::assertTrue($recorder->last->getInfo('canceled'), 'a cut cancels the response, closing the connection');
    }

    public function testACleanStreamEndIsNotCancelled(): void
    {
        // The body ends without [DONE]; the socket's own end is the clean
        // exit and needs no signal.
        $body = (static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"content":"done"}}]}'."\n\n";
        })();

        $recorder = $this->recordingClient(new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));
        $response = $this->makeClient($recorder)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertSame('done', $response->content);
        self::assertNull($response->stop);
        self::assertNotNull($recorder->last);
        self::assertFalse((bool) $recorder->last->getInfo('canceled'), 'a stream that ran to its own end needs no cancelling');
    }

    public function testATransportFailureMidStreamKeepsThePartialOutput(): void
    {
        $body = (static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"content":"half a thought"}}]}'."\n\n";
            yield new \Symfony\Component\HttpClient\Exception\TransportException('connection dropped');
        })();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertSame('half a thought', $response->content);
        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::StreamError, $response->stop->reason);
    }

    public function testATransportFailureWithNoOutputStaysAFailure(): void
    {
        $body = (static function (): \Generator {
            yield new \Symfony\Component\HttpClient\Exception\TransportException('connection dropped');
        })();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));

        $this->expectException(LlmRequestException::class);
        $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);
    }

    public function testAPartialToolCallIsDroppedAndCountedOnTheStop(): void
    {
        $body = (static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"c1","function":{"name":"send_email","arguments":"{\"to\":\"a"}}]}}]}'."\n\n";
            for ($i = 0; $i < 40; ++$i) {
                yield 'data: {"choices":[{"delta":{"content":"keep going keep going keep going keep going keep going "}}]}'."\n\n";
            }
        })();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::LoopDetected, $response->stop->reason);
        self::assertSame(1, $response->stop->droppedToolCalls, 'the half-written call was dropped, and the stop says so');
        self::assertSame([], $response->getToolCalls());
    }

    public function testReasoningOnlyLoopsAreCaughtAndReasoningIsCaptured(): void
    {
        $body = (static function (): \Generator {
            for ($i = 0; $i < 40; ++$i) {
                yield 'data: {"choices":[{"delta":{"reasoning_content":"I am still reasoning about the same thing again. "}}]}'."\n\n";
            }
        })();

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertNotNull($response->stop);
        self::assertSame(LlmStopReason::LoopDetected, $response->stop->reason);
        self::assertStringContainsString('thinking', $response->stop->detail);
        self::assertNotNull($response->reasoningContent);
    }

    public function testTheRequestCarriesStreamTrueAndTheGuardTickAsItsIdleWindow(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse(
                "data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\ndata: [DONE]\n\n",
                ['http_code' => 200],
            );
        });

        $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);

        $body = json_decode((string) ($captured['body'] ?? ''), true);
        self::assertIsArray($body);
        self::assertTrue($body['stream'] ?? null, 'stream:true is what makes any of this possible');
        self::assertSame(0.05, $captured['timeout'] ?? null, 'the request idle window and the guard tick are the same number');
    }
}
