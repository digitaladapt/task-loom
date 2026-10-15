<?php

declare(strict_types=1);

namespace App\Chat;

use App\Context\ContextExhaustedException;
use App\Context\ShedPlan;
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
 * head is never pruned: it is the conversation's constitution. The *newest*
 * turn is not optional either — it is where the loop resumes, and a request
 * that cannot fit it fails closed.
 *
 * And between those two rules sits the one this class originally missed:
 * **the question — the newest unit carrying a user-side turn — is never
 * shed.** Before the first tool round it *is* the newest turn, which is why
 * "the newest turn is the question being answered" read true right up until
 * it didn't: mid tool loop the newest turns are tool rounds and the question
 * sits behind them, where the oldest-first shed could reach it. The request
 * that remains then carries no user message at all, and the endpoint refuses
 * it outright ("No user query found in messages") — a raw provider failure
 * in front of a person, which is the exact shape of failure the fail-closed
 * discipline exists to prevent. The predecessor project recorded the same
 * trap: pruning may remove anything that is redundant, never anything that
 * is structurally required.
 *
 * So the question is reserved before any shedding — it rides whatever the
 * budget does to the turns around it — and a fit where the question and the
 * newest turn cannot sit together fails closed with a named reason, because
 * the only fit that excludes one of them is a request the endpoint already
 * refused or a loop that cannot continue. The read's half of the same
 * invariant — the read always reaches back to the question — is stated on
 * {@see \App\Repository\ChatExchangeEventRepository::findWireWindow()}.
 *
 * ## The budget is the model's; the read bound is the lane's
 *
 * The token fit is made against `TASKLOOM_CONTEXT_LIMIT` — the same knob the
 * run window uses, because the model's window is a fact about the model, not
 * about which lane is asking. What is chat-specific is the read bound
 * (`TASKLOOM_CHAT_WINDOW_TURNS`), which limits how deep into a conversation
 * one request reaches at all, on the same reasoning that gave chat its own
 * tool-round ceiling instead of reusing the run's step budget
 * (`CHAT_TOOLS.md` §4). The bound has one exception, and it is not history:
 * the read always reaches back to the question, wherever the bound falls,
 * because a bound whose whole purpose is a sendable request must never make
 * one unsendable. Everything the read left behind is counted into the trim
 * this class reports — nothing leaves the model's view unrecorded.
 */
final readonly class ChatContextWindow
{
    private const string TRUNCATED_MARKER = '…[truncated — tool result capped]';

    public function __construct(
        private int $contextLimitTokens,
        private float $maxToolOutputPct = 15.0,
        private int $windowTurns = 100,
        private float $contextTrimTargetPct = 50.0,
    ) {
    }

    /**
     * How many turns one request may reach back over.
     *
     * The engine passes this to the wire read
     * ({@see \App\Repository\ChatExchangeEventRepository::findWireWindow()}):
     * the read and the fit must agree on the bound, or the window would be
     * counting against a depth the read never claimed. The read's one
     * exception — the question always rides, wherever the bound falls — is
     * the read's to make, so the fit never sees a window this bound did not
     * describe.
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
     * many of the newest turns as the budget allows — with the question
     * reserved: the newest unit carrying a user-side turn is set aside before
     * the oldest-first drop starts, so the drop can take only the turns
     * around it (a request with no user message is refused by the endpoint).
     * Fails closed with a named reason if even the head — or the head plus
     * the question and the newest turn — cannot fit.
     *
     * **The front is sticky when a plan is supplied** ({@see ShedPlan}, docs/
     * design/KV_CACHE.md §6.2, fixing B2). The plan's `base` is the **wire
     * event id** that begins the retained window's front — an id, not an
     * index, because the read window slides as the transcript grows and an
     * index would name a different turn next time. Units older than that id
     * are out of view by this conversation's own choice, not the budget's, so
     * an unchanged base across turns holds the front still and the server's
     * prefix cache hits. A `base` that is null, or names a unit the read no
     * longer carries, means "from the head" — the window's stateless shape.
     *
     * The plan's `batch` sheds a whole batch down to the headroom target when
     * the budget forces anything out (one re-prefill, then a long stable
     * stretch), and the caller records where the front landed so the next
     * turn re-anchors to it. The front never passes the question: a retained
     * stretch that would exclude it is not produced — the question is where
     * the reply resumes, and the endpoint refuses a request without one.
     *
     * @param ChatWireWindow             $window          the wire read: the newest whole turns, plus what it left behind
     * @param list<array<string, mixed>> $toolDescriptors the OpenAI tool descriptors sent on the same request
     * @param ?ShedPlan                  $plan            the caller-owned sticky front id and batch flag, or null
     *                                                    to keep the window's own stateless behavior
     *
     * @throws ContextExhaustedException when the head, or the head plus the question and the newest turn, exceeds the budget
     */
    public function fit(ChatWireWindow $window, string $systemMessage, array $toolDescriptors = [], ?ShedPlan $plan = null): ChatContextWindowResult
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

        // Render and price each unit once, oldest..newest.
        $costs = [];
        $rendered = [];
        foreach ($units as $index => $unit) {
            $messages = self::renderUnit($unit);
            $rendered[$index] = $messages;
            $costs[$index] = $this->tokensFor(self::messagesChars($messages));
        }

        $newest = \count($units) - 1;

        // The question — the newest unit carrying a user-side turn. It is
        // reserved before any shedding, because a request it cannot ride in
        // is one the endpoint refuses and a person watches fail.
        $question = null;
        for ($index = $newest; $index >= 0; --$index) {
            if (self::carriesUserTurn($units[$index])) {
                $question = $index;
                break;
            }
        }

        if (null === $question) {
            // Defensive: a reply is only compiled once an inbound turn
            // exists, so this arm is unreachable in the steady state — and it
            // is a refusal, not a send, because there is no request this
            // window could legally become.
            throw new ContextExhaustedException('context exhausted: no question is in the window — no unit carries a user-side turn, and a request with no user message is refused by the endpoint.');
        }

        if ($fixedTokens + $costs[$newest] > $this->contextLimitTokens) {
            throw new ContextExhaustedException(\sprintf('context exhausted: the prompt head, tool definitions, and the newest turn alone are an estimated %d tokens > limit %d', $fixedTokens + $costs[$newest], $this->contextLimitTokens));
        }

        // And the question and the newest turn must fit *together*: keeping
        // either while shedding the other is a request the endpoint refuses,
        // or one the loop cannot continue from.
        if ($question !== $newest && $fixedTokens + $costs[$newest] + $costs[$question] > $this->contextLimitTokens) {
            throw new ContextExhaustedException(\sprintf('context exhausted: the question and the newest turn, with the prompt head and tool definitions, are an estimated %d tokens > limit %d — the question is never shed, and the newest turn is where the loop resumes', $fixedTokens + $costs[$newest] + $costs[$question], $this->contextLimitTokens));
        }

        // The sticky floor: the first unit at or after the plan's anchor id.
        // A missing anchor (null, or evicted by the read bound) is the head.
        // The question is reserved separately, so it rides even when it sits
        // *before* the floor — the turns between them are out of view by this
        // conversation's own choice, which is what makes the front still.
        $front = null === $plan || null === $plan->base
            ? 0
            : self::unitIndexAtOrAfterId($units, $plan->base);
        $front = max(0, min($front, $newest));

        // Newest-first, oldest dropped until the window fits, within the
        // floor — with the question reserved: a drop may take only turns that
        // are neither the question nor the newest.
        $tokens = $fixedTokens;
        $kept = [];
        if ($question !== $newest) {
            $tokens += $costs[$question];
            $kept[$question] = true;
        }

        for ($index = $newest; $index >= $front; --$index) {
            if (isset($kept[$index])) {
                continue;
            }

            if ($tokens + $costs[$index] > $this->contextLimitTokens) {
                break;
            }

            $tokens += $costs[$index];
            $kept[$index] = true;
        }

        // Batched shedding: once this fit has been forced to shed anything,
        // keep shedding down to the headroom target — one re-prefill now, a
        // long stable stretch after (docs/design/KV_CACHE.md §6.2). The
        // question and the newest turn are never shed by it, exactly as the
        // oldest-first pass keeps them.
        $candidateTotal = $newest - $front + 1 + ($question < $front ? 1 : 0);
        if (null !== $plan && $plan->batch && \count($kept) < $candidateTotal) {
            $target = $this->trimTargetTokens();

            for ($index = $front; $index <= $newest && $tokens > $target; ++$index) {
                if (isset($kept[$index]) && $index !== $question && $index !== $newest) {
                    unset($kept[$index]);
                    $tokens -= $costs[$index];
                }
            }
        }

        $keptMessages = [];
        $keptTurns = 0;
        $keptRounds = 0;
        $droppedTurns = $window->olderTurns;
        $droppedRounds = $window->olderRounds;

        foreach ($units as $index => $unit) {
            if (isset($kept[$index])) {
                $keptMessages = array_merge($keptMessages, $rendered[$index]);
                if (self::isRound($unit)) {
                    ++$keptRounds;
                } else {
                    ++$keptTurns;
                }
            } elseif ($index >= $front) {
                // Dropped by THIS fit — a candidate the budget (or a batch)
                // shed. A unit before the front is out of view by an earlier
                // shed and was counted then; re-counting it here would make
                // `trimmed()` true every turn and log a trim for a shed that
                // already happened.
                if (self::isRound($unit)) {
                    ++$droppedRounds;
                } else {
                    ++$droppedTurns;
                }
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

        // Where the retained window's front now stands, as a wire id: the
        // oldest unit of the CONTIGUOUS newest stretch that was kept. The
        // caller stores this as the next turn's anchor, so an unforced fit
        // keeps the same front and only a shed moves it.
        //
        // The stretch, not "the oldest kept unit", is what matters: the
        // question is reserved and often sits far behind the retained turns,
        // so anchoring on it would drag the front back to the head every turn
        // and defeat the whole point. Walking down from the newest while the
        // units are still kept lands on the front of the block that actually
        // travels, which is the thing a prefix cache keys on.
        $frontId = null;
        for ($index = $newest; $index >= 0; --$index) {
            if (!isset($kept[$index])) {
                break;
            }
            $frontId = $units[$index][0]->getId();
        }

        return new ChatContextWindowResult(
            messages: [['role' => 'system', 'content' => $systemMessage], ...$keptMessages],
            keptTurns: $keptTurns,
            keptRounds: $keptRounds,
            droppedTurns: $droppedTurns,
            droppedRounds: $droppedRounds,
            estimatedTokens: $tokens,
            limitTokens: $this->contextLimitTokens,
            contextBaseId: $frontId,
        );
    }

    /**
     * The index of the first unit whose starting wire row is at or after the
     * given event id, or 0 when no unit qualifies — the head, which is what a
     * null or evicted sticky anchor means ({@see fit()}).
     *
     * Wire row ids increase with reading order, so "at or after the anchor"
     * is a single forward scan; a unit boundary is always a turn-starting
     * row, so the match lands exactly on a whole turn.
     *
     * @param list<list<ChatExchangeEvent>> $units
     */
    private static function unitIndexAtOrAfterId(array $units, int $baseId): int
    {
        foreach ($units as $index => $unit) {
            $id = $unit[0]->getId();

            // A unit whose starting row has no id yet (an unpersisted row a
            // test handed us) cannot be the anchor, so the scan skips it; a
            // real read always persists its rows first.
            if (null !== $id && $id >= $baseId) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * The token target a batch trim sheds down to: `context_trim_target_pct`
     * of the whole request budget (docs/design/KV_CACHE.md §6.2).
     *
     * Never the entire limit: a target that reaches it would shed to a window
     * that already fails to fit on the next turn, which is no headroom at
     * all. Clamped just below the limit so the knob can be set aggressively
     * without inverting its meaning.
     */
    private function trimTargetTokens(): int
    {
        $pct = max(1.0, min($this->contextTrimTargetPct, 99.0));

        return (int) floor($this->contextLimitTokens * $pct / 100);
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
     * Whether this unit carries the user side of the conversation — the
     * question, wherever it sits ({@see ChatExchangeEvent::isUserTurn()}).
     *
     * @param list<ChatExchangeEvent> $unit
     */
    private static function carriesUserTurn(array $unit): bool
    {
        return $unit[0]->isUserTurn();
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
