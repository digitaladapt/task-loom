<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * One parsed assistant turn from an OpenAI-compatible endpoint.
 *
 * toolCalls entries: {id, name, arguments} with arguments already decoded
 * from the JSON string to an array. reasoningContent is captured for the
 * ledger but never re-sent (SPEC §5.6).
 */
final readonly class LlmResponse
{
    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array<string, mixed>                                                   $usage
     */
    public function __construct(
        public ?string $content,
        public string $finishReason,
        public array $toolCalls,
        public array $usage,
        public ?string $reasoningContent,
        public int $durationMs,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function wantsToolCall(): bool
    {
        return [] !== $this->toolCalls;
    }
}
