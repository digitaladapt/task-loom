<?php

declare(strict_types=1);

namespace App\Context;

/**
 * The outcome of fitting a run's messages to the context budget
 * ({@see ContextWindow::buildMessages()}).
 *
 * It carries what the caller needs beyond the messages themselves: how many
 * of the candidate tail exchanges the adaptive trim kept and dropped, and
 * the estimated token cost of what was kept — so the engine can record a
 * `context_trim` ledger row without recomputing the estimate or guessing
 * whether a trim happened at all.
 */
final readonly class ContextWindowResult
{
    /**
     * @param list<array{role: string, content: ?string, tool_calls?: list<array<string, mixed>>, tool_call_id?: string}> $messages
     * @param int                                                                                                         $keptExchanges    candidate tail exchanges that fit
     * @param int                                                                                                         $droppedExchanges candidate tail exchanges the budget forced out, oldest first
     * @param int                                                                                                         $estimatedTokens  estimated cost of the head + kept exchanges + tool definitions
     * @param int                                                                                                         $limitTokens      the budget this was fitted to
     */
    public function __construct(
        public array $messages,
        public int $keptExchanges,
        public int $droppedExchanges,
        public int $estimatedTokens,
        public int $limitTokens,
    ) {
    }

    /** Whether the adaptive trim had to shed anything to fit the budget. */
    public function trimmed(): bool
    {
        return $this->droppedExchanges > 0;
    }
}
