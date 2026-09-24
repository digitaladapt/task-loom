<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One LLM turn of a run: the model has the floor next (SPEC §6).
 *
 * Carries only what a fresh worker needs to locate the run and verify the
 * delivery is still the turn it expects — the run's state lives in the run
 * row + checkpoint, never in the message. `step` is the 1-based LLM-exchange
 * number this message is for; a message whose step no longer matches the
 * run's state is dropped as stale by the engine (duplicate delivery is
 * normal in an at-least-once queue).
 *
 * Re-enqueued turns land at the back of the `llm` lane (plain row-insert
 * order), so a long multi-step run cannot starve others: fairness is FIFO.
 * One delivery of this message = one LLM request = the unit the
 * TASKLOOM_LLM_MAX_CONCURRENCY semaphore is counted in: N workers consuming
 * `llm` means at most N requests on the wire.
 */
final readonly class LlmTurnMessage
{
    public function __construct(
        public int $runId,
        public int $step,
    ) {
    }
}
