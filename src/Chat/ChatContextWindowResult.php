<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * The outcome of fitting a conversation to the chat context window
 * ({@see ChatContextWindow::fit()}).
 *
 * It carries what the engine needs beyond the messages themselves: how many
 * of the candidate turns and tool rounds the window kept and dropped, and
 * the estimated token cost of what was kept — so the engine can record a
 * `context_trim` ledger row without recomputing the estimate or guessing
 * whether a trim happened at all. The run window's result carries the same
 * facts under its own nouns.
 */
final readonly class ChatContextWindowResult
{
    /**
     * @param list<array{role: string, content: ?string, tool_calls?: list<array<string, mixed>>, tool_call_id?: string}> $messages
     * @param int                                                                                                         $keptTurns       conversational turns that fit
     * @param int                                                                                                         $keptRounds      tool rounds that fit
     * @param int                                                                                                         $droppedTurns    conversational turns out of the model's view
     * @param int                                                                                                         $droppedRounds   tool rounds out of the model's view
     * @param int                                                                                                         $estimatedTokens estimated cost of the head + kept turns + tool definitions
     * @param int                                                                                                         $limitTokens     the budget this was fitted to
     */
    public function __construct(
        public array $messages,
        public int $keptTurns,
        public int $keptRounds,
        public int $droppedTurns,
        public int $droppedRounds,
        public int $estimatedTokens,
        public int $limitTokens,
    ) {
    }

    /** Whether the window had to shed anything at all. */
    public function trimmed(): bool
    {
        return $this->droppedTurns > 0 || $this->droppedRounds > 0;
    }
}
