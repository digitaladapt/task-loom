<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One tool turn of a run: execute the tool calls the model just requested
 * (SPEC §5.1) — every call of the step, with the engine's own
 * validate → dispatch → retry-with-feedback loop.
 *
 * The calls themselves are NOT in the message: they are read from the run's
 * checkpoint, where the LLM turn persisted them before this message was
 * dispatched (write-then-dispatch: a redelivered tool turn after a crashed
 * worker finds its work item intact). `step` is the LLM-exchange number the
 * calls belong to.
 *
 * Tools run on their own lane, so a slow tool never blocks the `llm` lane
 * and vice versa. When the tool turn finishes it re-enqueues the run's next
 * LLM turn at the back of the `llm` lane.
 */
final readonly class ToolTurnMessage
{
    public function __construct(
        public int $runId,
        public int $step,
    ) {
    }
}
