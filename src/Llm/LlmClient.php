<?php

declare(strict_types=1);

namespace App\Llm;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal OpenAI-compatible chat-completions client (SPEC §2.3: local-first,
 * Ollama/vLLM/llama.cpp — or any gateway speaking the same dialect).
 *
 * Only what the run loop needs: messages in, one assistant turn out, with
 * tool_calls parsed from JSON strings to arrays. No streaming, no
 * logprobs, no function-call legacy shape.
 *
 * The model's `reasoning_content` (thinking models) is captured in the
 * response payload but NEVER re-sent as conversation content (SPEC §5.6:
 * prior thinking blocks are never re-sent).
 */
final class LlmClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey = '',
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    /**
     * @param array<string, mixed> $config base_url, model, api_key, timeout
     */
    public static function fromConfig(array $config, ?HttpClientInterface $httpClient = null): self
    {
        return new self(
            $httpClient ?? HttpClient::create(),
            (string) ($config['base_url'] ?? ''),
            (string) ($config['model'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            (int) ($config['timeout'] ?? 300),
        );
    }

    #[\Override]
    public function chat(array $messages, array $tools = []): LlmResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        if ([] !== $tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $headers = ['Content-Type' => 'application/json'];
        if ('' !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/v1/chat/completions', [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (TransportException $e) {
            throw LlmRequestException::transport($e);
        } catch (\Throwable $e) {
            throw LlmRequestException::transport($e);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if (200 !== $status) {
            throw LlmRequestException::httpError($status, $this->errorDetail($body));
        }

        try {
            return self::parseResponse($body, $durationMs);
        } catch (\Throwable $e) {
            throw LlmRequestException::malformed($e);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function parseResponse(array $body, int $durationMs): LlmResponse
    {
        $choice = $body['choices'][0] ?? null;
        if (!\is_array($choice)) {
            throw new \RuntimeException('Response has no choices[0].');
        }

        $message = $choice['message'] ?? null;
        if (!\is_array($message)) {
            throw new \RuntimeException('Response has no message.');
        }

        $content = $message['content'] ?? null;
        if (null !== $content && !\is_string($content)) {
            throw new \RuntimeException('Response message.content is not a string.');
        }

        $finishReason = $choice['finish_reason'] ?? null;
        if (!\is_string($finishReason) && null !== $finishReason) {
            throw new \RuntimeException('Response finish_reason is not a string.');
        }

        $toolCalls = [];
        foreach (($message['tool_calls'] ?? []) as $call) {
            if (!\is_array($call)) {
                continue;
            }
            $toolCalls[] = self::parseToolCall($call);
        }

        $usage = $body['usage'] ?? [];
        $usageArray = \is_array($usage) ? $usage : [];

        return new LlmResponse(
            content: $content,
            finishReason: \is_string($finishReason) ? $finishReason : '',
            toolCalls: $toolCalls,
            usage: $usageArray,
            reasoningContent: \is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : null,
            durationMs: $durationMs,
        );
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    private static function parseToolCall(array $call): array
    {
        $id = $call['id'] ?? null;
        $function = $call['function'] ?? null;

        if (!\is_string($id) || '' === $id || !\is_array($function)) {
            throw new \RuntimeException('Tool call is missing id or function.');
        }

        $name = $function['name'] ?? null;
        $arguments = $function['arguments'] ?? null;

        if (!\is_string($name) || '' === $name) {
            $name = '';
        }

        if (null === $arguments || '' === $arguments) {
            $arguments = [];
        } elseif (\is_string($arguments)) {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('Tool call arguments did not decode to an object.');
            }
            $arguments = $decoded;
        } elseif (!\is_array($arguments)) {
            throw new \RuntimeException('Tool call arguments are neither string nor array.');
        }

        return [
            'id' => $id,
            'name' => $name,
            'arguments' => $arguments,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorDetail(array $body): string
    {
        $message = $body['error']['message'] ?? null;

        return \is_string($message) ? $message : 'unknown error';
    }
}
