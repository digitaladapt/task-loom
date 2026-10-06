<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The chat ledger's event types (SPEC §15) — a fixed vocabulary, never free
 * text. The rung below `RunEventType`: one exchange's LLM request, its
 * reply, and the failure that stopped it.
 *
 * Deliberately short, and deliberately only what this version emits. The run
 * ledger once carried a `context_trim` type that nothing wrote, so a reader
 * could not tell "nothing was trimmed" from "trimming was not recorded"; a
 * vocabulary is a promise, and an unemitted case is a broken one. New cases
 * arrive with the code that writes them — tools, for instance, would add
 * `tool_call` / `tool_result` alongside the machinery.
 */
enum ChatEventType: string
{
    /** An inbound turn: something the human said. Conversational. */
    case Message = 'message';

    /** An outbound turn: something the assistant said. Conversational. */
    case Reply = 'reply';

    /** The model was asked. */
    case LlmRequest = 'llm_request';

    /** The model answered. */
    case LlmResponse = 'llm_response';

    /** The exchange's resume position advanced. */
    case Checkpoint = 'checkpoint';

    /** The exchange ended badly, with a classified reason. */
    case Failure = 'failure';

    /**
     * Whether this event is part of the transcript — one of the turns a
     * participant actually said — as opposed to the machinery around it.
     *
     * The transcript is a *filtered read* over the ledger (SPEC §15, and the
     * design note's decision to have no separate turn table), so this
     * predicate is the filter, stated once here rather than spelled out as a
     * literal list in every query.
     */
    public function isConversational(): bool
    {
        return self::Message === $this || self::Reply === $this;
    }
}
