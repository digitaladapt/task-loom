<?php

declare(strict_types=1);

namespace App\Stream;

use App\Entity\StreamEvent;
use App\Entity\StreamRole;

/**
 * Stream rows → the exchange windows the context compiler already understands
 * (docs/design/THE_STREAM.md §2.1).
 *
 * ## Why a translation, and not a new window API
 *
 * The window's input shape — a list of
 * `{assistant: {content, toolCalls}, toolResults: [...]}` — is what the trim
 * arithmetic, the batching in {@see \App\Context\ShedPlan} and the render cap
 * are all written against, and it is the shape an *exchange* has: one assistant
 * turn plus the results answering its calls, as a unit that is kept or dropped
 * whole. A stream is a finer grain than that, so it has to be re-assembled into
 * exchanges before the window sees it. Rewriting the window to take rows
 * directly would mean redoing the trim unit at the same time, which is two
 * changes where one is being tested.
 *
 * ## The reassembly rule
 *
 * An assistant row opens an exchange; the `tool` rows that follow it are its
 * results; a `user` row closes whatever was open and stands as its own
 * exchange (the window already knows how to render an assistant-less exchange —
 * that is what a conversation's earlier human turns are). Rows that fit none of
 * these are skipped rather than guessed at: the protocol has three roles and a
 * row that cannot be placed would render as a malformed request.
 *
 * Results are attached to the exchange by position, not by matching
 * `tool_call_id` back to a call. The protocol requires a result message to
 * follow its call immediately, so consecutive `tool` rows *are* the round; a
 * lookup would be a second, weaker statement of the same fact.
 */
final class StreamWindow
{
    /**
     * Fold rows into exchange windows, oldest first.
     *
     * @param iterable<StreamEvent> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function toExchanges(iterable $rows): array
    {
        $exchanges = [];
        $open = null;

        foreach ($rows as $row) {
            switch ($row->getRole()) {
                case StreamRole::Assistant:
                    if (null !== $open) {
                        $exchanges[] = $open;
                    }
                    $open = [
                        'assistant' => [
                            'content' => $row->getContent(),
                            'toolCalls' => $row->getToolCalls(),
                        ],
                        'toolResults' => [],
                    ];
                    break;

                case StreamRole::Tool:
                    // A tool row with nothing open is an orphan: there is no
                    // assistant message announcing the call it answers, and
                    // sending it alone would be a malformed request. Dropping
                    // it is the only honest option — but it is also a bug
                    // upstream, so the shape stays visible in the ledger.
                    if (null === $open) {
                        break;
                    }
                    foreach ($row->getToolResults() as $result) {
                        $open['toolResults'][] = $result;
                    }
                    break;

                case StreamRole::User:
                    if (null !== $open) {
                        $exchanges[] = $open;
                        $open = null;
                    }
                    $exchanges[] = [
                        'assistant' => [
                            'content' => $row->getContent(),
                            'toolCalls' => [],
                        ],
                        'toolResults' => [],
                        // The operator's own voice. The window sends this on
                        // the `user` side, not `assistant`: it is a message to
                        // her, and dating it as her own turn would tell the
                        // model it had said something it had not.
                        'userSide' => true,
                    ];
                    break;
            }
        }

        if (null !== $open) {
            $exchanges[] = $open;
        }

        return $exchanges;
    }
}
