<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Entity\ErrorClass;
use App\Llm\LlmClient;
use App\Llm\LlmRequestException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LlmClient against an in-process MockHttpClient: parse correctness for
 * plain text, tool calls (JSON-string arguments), reasoning models, and
 * the classified error taxonomy.
 */
final class LlmClientTest extends TestCase
{
    private function makeClient(MockHttpClient $http): LlmClient
    {
        return new LlmClient($http, 'https://llm.example', 'test-model', 'secret-key', 30);
    }

    public function testParsesPlainTextResponse(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'pong'],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]) ?: '{}',
            ['http_code' => 200],
        ));

        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'ping']]);

        self::assertSame('stop', $response->finishReason);
        self::assertSame('pong', $response->content);
        self::assertSame([], $response->getToolCalls());
        self::assertFalse($response->wantsToolCall());
        self::assertSame(12, $response->usage['total_tokens']);
    }

    public function testParsesToolCallsWithJsonStringArguments(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_weather',
                                'arguments' => '{"location":"Reykjavik"}',
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['total_tokens' => 100],
            ]) ?: '{}',
            ['http_code' => 200],
        ));

        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'weather?']]);

        self::assertSame('tool_calls', $response->finishReason);
        self::assertTrue($response->wantsToolCall());
        $calls = $response->getToolCalls();
        self::assertSame('call_1', $calls[0]['id']);
        self::assertSame('get_weather', $calls[0]['name']);
        self::assertSame(['location' => 'Reykjavik'], $calls[0]['arguments']);
    }

    public function testCapturesReasoningContentWithoutResending(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'pong',
                        'reasoning_content' => 'thinking about ping',
                    ],
                ]],
                'usage' => [],
            ]) ?: '{}',
            ['http_code' => 200],
        ));

        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'ping']]);

        self::assertSame('pong', $response->content);
        self::assertSame('thinking about ping', $response->reasoningContent);
    }

    public function testEmptyToolCallArgumentsDecodeToEmptyArray(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_2',
                            'type' => 'function',
                            'function' => ['name' => 'echo', 'arguments' => ''],
                        ]],
                    ],
                ]],
                'usage' => [],
            ]) ?: '{}',
            ['http_code' => 200],
        ));

        $response = $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);

        self::assertSame([], $response->getToolCalls()[0]['arguments']);
    }

    public function testHttpErrorIsClassifiedLlmError(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['error' => ['message' => 'Invalid API Key']]) ?: '{}',
            ['http_code' => 401],
        ));

        try {
            $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);
            self::fail('LlmRequestException was not thrown');
        } catch (LlmRequestException $e) {
            self::assertSame(ErrorClass::LlmError, $e->errorClass);
            self::assertStringContainsString('HTTP 401', $e->getMessage());
        }
    }

    public function testMalformedResponseIsClassifiedLlmMalformedResponse(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"choices": "not an array"}',
            ['http_code' => 200],
        ));

        try {
            $this->makeClient($http)->chat([['role' => 'user', 'content' => 'x']]);
            self::fail('LlmRequestException was not thrown');
        } catch (LlmRequestException $e) {
            self::assertSame(ErrorClass::LlmMalformedResponse, $e->errorClass);
        }
    }

    public function testRequestPayloadIncludesToolsAndAuthHeader(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']]],
            ]) ?: '{}');
        });

        $this->makeClient($http)->chat(
            [['role' => 'user', 'content' => 'x']],
            [['type' => 'function', 'function' => ['name' => 'echo', 'description' => '', 'parameters' => ['type' => 'object']]]],
        );

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://llm.example/v1/chat/completions', $requests[0]['url']);

        $body = json_decode((string) ($requests[0]['options']['body'] ?? ''), true);
        self::assertIsArray($body);
        self::assertSame('test-model', $body['model']);
        self::assertSame('auto', $body['tool_choice']);
        self::assertCount(1, $body['tools']);
        self::assertContains('Authorization: Bearer secret-key', $requests[0]['options']['normalized_headers']['authorization'] ?? []);
    }
}
