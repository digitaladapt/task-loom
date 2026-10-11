<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One LLM turn of a session slice: the model has the floor next
 * (docs/design/SESSION_TASKS.md §7).
 *
 * The same shape discipline as `LlmTurnMessage` — ids only, state in the
 * row, validated under the claim — because it is the same turn core
 * (`RunEngine::llmTurn`). What the class names is the LANE: a session
 * slice's LLM turns ride the `session` transport, below `llm`, so the
 * ordering is exactly chat > task > session and a session's turn is taken
 * before nothing and after everything. That is the whole session
 * preemption mechanism — no new process, no lock, no residency cap.
 *
 * The engine constructs this message (never `LlmTurnMessage`) for every
 * LLM turn of a session-kind run — the first turn, the requeue, and the
 * turn re-enqueued after a tool turn. A slice's TOOL turns stay
 * `ToolTurnMessage` on the shared `tools` lane: a tool turn holds no
 * model, so it is not the resource the lanes order.
 */
final readonly class SessionTurnMessage
{
    public function __construct(
        public int $runId,
        public int $step,
    ) {
    }
}
