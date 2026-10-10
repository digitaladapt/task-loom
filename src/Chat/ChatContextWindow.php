<?php

declare(strict_types=1);

namespace App\Chat;

use App\Context\ContextExhaustedException;
use App\Context\TokenEstimate;
use App\Entity\ChatEventType;
use App\Entity\ChatExchangeEvent;

/**
 * The chat context window: a conversation's wire transcript, fitted to the
 * model's token budget (SPEC §15.9).
 *
 * A `Run` ends, so its context is bounded by construction; a conversation
 * does not end, so its transcript grows forever (the design note's §10.2,
 * which is where this policy was named as the open problem). This is the
 * analogous policy, and it is the run engine's discipline unchanged: fit
 * statically, shed the oldest, fail closed with a named reason, and record
 * the shedding. No LLM summarization — rejected in DESIGN_CONSIDERATIONS
 * §2.3 as silently lossy, for a conversation as much as for a run.
 *
 * ## The unit is a whole turn
 *
 * The window's unit is **a turn**: the smallest slice of the wire transcript
 * that travels whole. Two shapes:
 *
 *  - a conversational turn — a `message` or `reply` row, one wire message;
 *  - a tool round — the assistant's `tool_calls` row together with the
 *    `tool_result`/`tool_error` rows that answer it.
 *
 * The round is indivisible for the same reason the run window drops a whole
 * exchange: a `tool` message with no preceding `tool_calls` is a malformed
 * request, and a `tool_calls` message whose results are missing is one too.
 * So the oldest turns are shed whole — never half a round — until what
 * remains fits. The unit boundary is stated once, on the vocabulary
 * ({@see ChatEventType::startsWireUnit()}), so the read that
 * cuts at turn boundaries and the fit that sheds whole turns cannot drift
 * apart.
 *
 * ## What is always kept
 *
 * The prompt head (the system message) is never trimmed, exactly as a run's
 * head is never pruned: it is the conversation's constitution. And because a
 * chat's whole reason for a request is the turn being answered, the *newest*
 * turn is not optional either — a request that cannot fit the newest turn
 * fails closed instead of sending a conversation with no question in it.
 *
 * ## The budget is the model's; the read bound is the lane's
 *
 * The token fit is made against `TASKLOOM_CONTEXT_LIMIT` — the same knob the
 * run window uses, because the model's window is a fact about the model, not
 * about which lane is asking. What is chat-specific is the read bound
 * (`TASKLOOM_CHAT_WINDOW_TURNS`), which limits how deep into a conversation
 * one request reaches at all, on the same reasoning that gave chat its own
 * tool-round ceiling instead of reusing the run's step budget
 * (`CHAT_TOOLS.md` §4). Everything the read left behind is counted into the
 * trim this class reports — nothing leaves the model's view unrecorded.
 */
final readonly class ChatContextWindow
{
    private const string TRUNCATED_MARKER = '…[truncated — tool result capped]';

    public function __construct(
        private int $contextLimitTokens,
        private float $maxToolOutputPct = 15.0,
        private int $windowTurns = 100,
    ) {
    }

    /**
     * How many turns one request may reach back over.
     *
     * The engine passes this to the wire read
     * ({@see \App\Repository\ChatExchangeEventRepository::findWireWindow()}):
     * the read and the fit must agree on the bound, or the window would be
     * counting against a depth the read never claimed.
     */
    public function maxTurns(): int
    {
        return max(1, $this->windowTurns);
    }

    /**
     * Cap a single tool result so one verbose tool cannot fill the
     * conversation.
     *
     * The run engine routes this through `ContextWindow::capToolResult`; the
     * arithmetic is shared through {@see TokenEstimate}, and it lives here
     * because the chat window is now the chat's home for it — the earlier
     * copy read the two knobs out of the raw environment by name, which was
     * one place that could silently disagree with the container's resolved
     * values.
     *
     * Data, not instructions: the stored value is whatever the server
     * returned; this bounds the copy sent to the model.
     */
    public function capToolResult(string $result): string
    {
        $maxChars = (int) floor($this->contextLimitTokens * TokenEstimate::CHARS_PER_TOKEN * $this->maxToolOutputPct / 100);

        if ($maxChars <= 0 || \strlen($result) <= $maxChars) {
            return $result;
        }

        return substr($result, 0, $maxChars)."\n".self::TRUNCATED_MARKER;
    }

    /**
     * Build the message list sent to the model: the system message, then as
     * many of the newest turns as the budget allows. Fails closed with a
     * named reason if even the head — or the head plus the newest turn —
     * cannot fit.
     *
     * @param ChatWireWindow             $window          the wire read: the newest whole turns, plus what it left behind
     * @param list<array<string, mixed>> $toolDescriptors the OpenAI tool descriptors sent on the same request
     *
     * @throws ContextExhaustedException when the head, or the head plus the newest turn, exceeds the budget
     */
    public function fit(ChatWireWindow $window, string $systemMessage, array $toolDescriptors = []): ChatContextWindowResult
    {
        [$units, $droppedFragment] = self::groupIntoUnits($window->rows);

        // The fixed cost of every request: the head and the tool definitions
        // that travel beside the messages. Both count — the estimate must
        // describe the request, not just its message array.
        $fixedChars = \strlen($systemMessage) + \strlen((string) json_encode($toolDescriptors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $fixedTokens = $this->tokensFor($fixedChars);

        if ($fixedTokens > $this->contextLimitTokens) {
            throw new ContextExhaustedException(\sprintf('context exhausted: the prompt head and tool definitions alone are an estimated %d tokens > limit %d (the head is never trimmed)', $fixedTokens, $this->contextLimitTokens));
        }

        if ([] === $units) {
            // Defensive: a reply is only compiled when an inbound turn
            // exists, so this arm is unreachable in the steady state.
            throw new ContextExhaustedException('context exhausted: no turn of this conversation could be sent.');
        }

        // Render and price each candidate once, newest last.
        $costs = [];
        $rendered = [];
        foreach ($units as $index => $unit) {
            $messages = self::renderUnit($unit);
            $rendered[$index] = $messages;
            $costs[$index] = $this->tokensFor(self::messagesChars($messages));
        }

        $newest = \count($units) - 1;
        if ($fixedTokens + $costs[$newest] > $this->contextLimitTokens) {
            throw new ContextExhaustedException(\sprintf('context exhausted: the prompt head, tool definitions, and the newest turn alone are an estimated %d tokens > limit %d', $fixedTokens + $costs[$newest], $this->contextLimitTokens));
        }

        // Newest-first, oldest dropped until the window fits. The drop stops
        // at the first turn that does not fit, so what remains is one
        // contiguous stretch of the end of the conversation.
        $tokens = $fixedTokens;
        $keptIndexes = [];
        for ($index = $newest; $index >= 0; --$index) {
            if ($tokens + $costs[$index] > $this->contextLimitTokens) {
                break;
            }

            $tokens += $costs[$index];
            $keptIndexes[$index] = true;
        }

        $keptMessages = [];
        $keptTurns = 0;
        $keptRounds = 0;
        $droppedTurns = $window->olderTurns;
        $droppedRounds = $window->olderRounds;

        foreach ($units as $index => $unit) {
            if (isset($keptIndexes[$index])) {
                $keptMessages = array_merge($keptMessages, $rendered[$index]);
                if (self::isRound($unit)) {
                    ++$keptRounds;
                } else {
                    ++$keptTurns;
                }
            } elseif (self::isRound($unit)) {
                ++$droppedRounds;
            } else {
                ++$droppedTurns;
            }
        }

        if ($droppedFragment) {
            // A leading result row with no `tool_calls` in view: a malformed
            // request if sent, so it is shed whole. The wire read cuts at turn
            // boundaries, so this only fires for a caller that handed the
            // window rows it did not promise — counted, never sent, never
            // silent.
            ++$droppedRounds;
        }

        return new ChatContextWindowResult(
            messages: [['role' => 'system', 'content' => $systemMessage], ...$keptMessages],
            keptTurns: $keptTurns,
            keptRounds: $keptRounds,
            droppedTurns: $droppedTurns,
            droppedRounds: $droppedRounds,
            estimatedTokens: $tokens,
            limitTokens: $this->contextLimitTokens,
        );
    }

    /**
     * Group wire rows into whole turns, and report whether a leading
     * fragment had to be refused.
     *
     * A unit starts at a conversational row or at a `tool_calls` row; every
     * `tool_result`/`tool_error` row attaches to the unit most recently
     * started. Result rows *before* any unit start mean the caller cut into
     * the middle of a round — the fragment is dropped whole, because a `tool`
     * message whose `tool_calls` is outside the window is a malformed
     * request.
     *
     * @param list<ChatExchangeEvent> $rows in reading order
     *
     * @return array{0: list<list<ChatExchangeEvent>>, 1: bool}
     */
    private static function groupIntoUnits(array $rows): array
    {
        $units = [];
        $current = -1;

        foreach ($rows as $row) {
            if ($row->getType()->startsWireUnit() || $current < 0) {
                $units[] = [];
                $current = \count($units) - 1;
            }

            $units[$current][] = $row;
        }

        $droppedFragment = [] !== $units && !$units[0][0]->getType()->startsWireUnit();

        if ($droppedFragment) {
            array_shift($units);
        }

        return [$units, $droppedFragment];
    }

    /** @param list<ChatExchangeEvent> $unit */
    private static function isRound(array $unit): bool
    {
        return ChatEventType::ToolCall === $unit[0]->getType();
    }

    /**
     * @param list<ChatExchangeEvent> $unit
     *
     * @return list<array<string, mixed>>
     */
    private static function renderUnit(array $unit): array
    {
        $messages = [];

        foreach ($unit as $row) {
            foreach ($row->toWireMessages() as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * The approximate character cost of a message list: every message's
     * text content, plus the encoded arguments of any tool calls it carries
     * (those travel on the wire too). The same arithmetic the run window
     * counts with, through the same {@see TokenEstimate}.
     *
     * @param list<array<string, mixed>> $messages
     */
    private static function messagesChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            $chars += \is_string($content) ? \strlen($content) : 0;

            foreach (($message['tool_calls'] ?? []) as $call) {
                $arguments = $call['function']['arguments'] ?? '';
                $chars += \is_string($arguments) ? \strlen($arguments) : 0;
            }
        }

        return $chars;
    }

    private function tokensFor(int $chars): int
    {
        return TokenEstimate::tokensForChars($chars);
    }
}
