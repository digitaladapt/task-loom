<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One exchange's reply turn: the model has the floor next (SPEC §15).
 *
 * The same shape discipline as `LlmTurnMessage`: ids only, state in the row,
 * validated under the claim. It carries no `step` because a v1 exchange is a
 * single LLM request with no tools — if a tool turn is ever added, the
 * exchange's checkpoint grows the position and this message grows the field,
 * the way the run's did.
 *
 * What this message *is*, in capacity terms, is the thing the `chat` lane
 * exists for: one delivery of it is one LLM request, so a `chat` lane drained
 * before `llm` means a waiting human is served before the next task turn
 * (`docs/design/CHAT_AND_CAPACITY.md` §4).
 */
final readonly class ChatReplyMessage
{
    public function __construct(
        public int $chatId,
        public int $exchangeId,
    ) {
    }
}
