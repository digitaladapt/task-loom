<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * One parsed assistant turn from an OpenAI-compatible endpoint.
 *
 * toolCalls entries: {id, name, arguments} with arguments already decoded
 * from the JSON string to an array. reasoningContent is captured for the
 * ledger but never re-sent (SPEC §5.6).
 *
 * `stop` and `metrics` are the streaming-capture additions. A response
 * that was cut short still returns normally — with the partial content,
 * the partial tool calls that were *complete*, and the stop that says why
 * (the PR's decision: partial output is output; only a cut that captured
 * nothing becomes an exception). `metrics` records the timing facts an
 * operator asks about after the fact ("how long was prefill? how long did
 * it talk before we cut it?").
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
        public ?LlmStop $stop = null,
        public ?LlmStreamMetrics $metrics = null,
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

    /**
     * Whether a guard (or a mid-stream failure) ended this generation
     * before the endpoint finished it.
     */
    public function wasStopped(): bool
    {
        return null !== $this->stop;
    }

    /**
     * The ledger additions a streamed response brings (SPEC §5.3): why it
     * ended early, if it did, and the timing facts. Split out here so the
     * run engine, the chat engine and any future caller record the same
     * shape — the alternative was each call site deciding its own spelling
     * of "stopped at the silence window".
     *
     * @return array<string, mixed>
     */
    public function ledgerAdditions(): array
    {
        $payload = [];

        if (null !== $this->stop) {
            $payload['stopped'] = $this->stop->toPayload();
        }

        if (null !== $this->metrics) {
            $payload['metrics'] = $this->metrics->toPayload();
        }

        return $payload;
    }
}
