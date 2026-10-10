<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatExchangeEvent;

/**
 * The wire rows of a conversation, read as whole turns, plus the exact count
 * of the turns the read did not carry ({@see \App\Repository\ChatExchangeEventRepository::findWireWindow()}).
 *
 * The read is bounded — a conversation grows without limit, and an unbounded
 * read per request is the shape this project refuses — but the bound is a
 * *turn* count, not a row count: rows are returned for the newest N turns
 * whole, never cut mid-round, because a `tool` message whose `tool_calls` is
 * outside the window is a malformed request. Everything older than the
 * boundary is reported here as counts, by kind, so the context window can
 * fold it into the trim it records (SPEC §15.9) instead of losing it
 * silently.
 */
final readonly class ChatWireWindow
{
    /**
     * @param list<ChatExchangeEvent> $rows        the newest turns' wire rows, in reading order, always beginning at a turn boundary
     * @param int                     $olderTurns  conversational turns older than the boundary
     * @param int                     $olderRounds tool rounds older than the boundary
     */
    public function __construct(
        public array $rows,
        public int $olderTurns = 0,
        public int $olderRounds = 0,
    ) {
    }
}
