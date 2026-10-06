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

    /**
     * The model asked for a tool (SPEC §15, `CHAT_TOOLS.md` §3).
     *
     * This row is the *evidence*, not the enforcement: dispatch is refused by
     * the frozen toolbox lookup (the same `tool_not_found` path a run uses),
     * and this records what was asked, by which exchange, so "did anything
     * dispatch that the model did not ask for?" is answerable from the ledger
     * rather than from a chain of implication. A chat transcript grows for
     * years and is half written by the model, so the run engine's free
     * assumptions are worth paying a row to keep.
     */
    case ToolCall = 'tool_call';

    /**
     * A tool's result, written by the executor (SPEC §15).
     *
     * Present in the transcript only from here: the prompt compiler may render
     * a `tool` role message *only* from an attested result row, never from
     * anything else in the ledger and never from a tool's own content that
     * happens to look like a result. Same reasoning as `ToolCall`.
     */
    case ToolResult = 'tool_result';

    /**
     * A tool call refused before dispatch, with the reason (SPEC §5.1).
     *
     * Kept distinct from `Failure` on purpose: an unknown tool, a schema
     * violation or a tool error is information the model gets back so it can
     * self-correct, *not* an exchange failure. A person should not lose their
     * reply because they or the model fumbled an argument.
     */
    case ToolError = 'tool_error';

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

    /**
     * Whether this event is part of the model's conversation window — a turn
     * somebody said, or an attested tool exchange.
     *
     * Broader than {@see isConversational()} on purpose, and the distinction
     * matters: the *transcript on the page* is what people said, while the
     * *messages sent to the model* also have to carry the tool calls and
     * results, or the model is re-asked a question whose answer it already
     * has. Keeping these as one predicate would put tool plumbing in the
     * conversation (noisy) or drop it from the request (broken).
     */
    public function isWireMessage(): bool
    {
        return $this->isConversational()
            || self::ToolCall === $this
            || self::ToolResult === $this
            // A refusal is feedback, and feedback has to reach the model or it
            // cannot self-correct — which is the entire reason a refused call
            // is not an exchange failure. Leaving it out also breaks the wire
            // shape: an assistant `tool_calls` message with no matching `tool`
            // result is a malformed request.
            || self::ToolError === $this;
    }
}
