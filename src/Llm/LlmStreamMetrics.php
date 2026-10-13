<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Timing facts about one streamed request, recorded whether it finished
 * cleanly or was cut short.
 *
 * `firstDeltaMs` is the number that answers "have I given too much context
 * and it's too bogged down to get anywhere": it is the wait for the
 * endpoint's first byte of model output, which is dominated by prefill, so a
 * large value on an otherwise successful call is the fingerprint of a heavy
 * prompt. `firstContentMs` is separated from it because thinking models
 * (qwen3, deepseek-r1) emit reasoning deltas long before visible content —
 * collapsing the two would make a thinking model look permanently bogged.
 */
final readonly class LlmStreamMetrics
{
    public function __construct(
        /** Model-output events received (content/reasoning/tool-call deltas). */
        public int $chunks = 0,
        /** Milliseconds from request start to the first model-output delta; null if none arrived. */
        public ?int $firstDeltaMs = null,
        /** Milliseconds from request start to the first visible-content delta; null if none arrived. */
        public ?int $firstContentMs = null,
        /** Milliseconds from request start to the end of the request, however it ended. */
        public int $elapsedMs = 0,
        /** How many times the soft deadline was extended because output was still flowing. */
        public int $extensions = 0,
    ) {
    }

    /**
     * The ledger shape (SPEC §5.3) — small integers, always safe to record.
     *
     * @return array{chunks: int, firstDeltaMs: int|null, firstContentMs: int|null, elapsedMs: int, extensions: int}
     */
    public function toPayload(): array
    {
        return [
            'chunks' => $this->chunks,
            'firstDeltaMs' => $this->firstDeltaMs,
            'firstContentMs' => $this->firstContentMs,
            'elapsedMs' => $this->elapsedMs,
            'extensions' => $this->extensions,
        ];
    }
}
