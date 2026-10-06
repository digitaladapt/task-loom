<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One tool turn of a chat exchange: execute the calls the model just requested
 * (SPEC §15, `docs/design/CHAT_TOOLS.md`).
 *
 * The run engine's `ToolTurnMessage` in a different aggregate, and it rides the
 * **same `tools` lane**. That is deliberate rather than convenient:
 *
 * - The tools workers exist because a tool turn is I/O-bound on somebody
 *   else's server, so it must not hold the model. That is just as true for a
 *   conversation, and giving chat its own tools lane would buy nothing except
 *   another lane to reason about and another CO-located requirement for the
 *   boot sweep's lane-ownership check.
 * - The tool *executor* is the same service, so both lane messages end up in
 *   the same code either way; a second lane would only change who picks it up.
 *
 * The consequence, stated because it is real and small: a chat's tool turn
 * queues behind task tool turns. Acceptable, and better than the alternative —
 * you do not want a side-effecting tool interrupted mid-flight.
 *
 * The calls are not in the message: they are read from the exchange's
 * checkpoint, where the LLM turn persisted them before this was dispatched
 * (write-then-dispatch, so a redelivered tool turn finds its work intact).
 */
final readonly class ChatToolTurnMessage
{
    public function __construct(
        public int $exchangeId,
    ) {
    }
}
