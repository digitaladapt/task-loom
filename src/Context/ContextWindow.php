<?php

declare(strict_types=1);

namespace App\Context;

/**
 * Static context window trimming with a fail-closed budget (SPEC §5.6).
 *
 * The initial user section — the task prompt — is never pruned (locked
 * decision 4). Only excessively old assistant messages from the LLM
 * itself are pruned: prior thinking/reasoning is never re-sent, and only
 * the newest tool-call exchanges are kept after the prompt head.
 *
 * **The window adapts to the budget.** Before, a fixed count
 * (`window_tail_exchanges`) of exchanges was sliced off and the whole
 * thing either fit the budget or threw. That is fine while each exchange
 * is small, but a run that reads many items — one exchange per tool
 * round-trip, each carrying a capped-but-large tool result — accumulates a
 * tail that can exceed the limit on its own while every exchange in it is
 * individually within budget. The run then fails closed for the one reason
 * it should not have: a shape the operator can neither see coming nor tune
 * out, because the number that overflows is the pruned tail itself, not the
 * fixed head.
 *
 * So the tail is fitted, not counted into: candidate exchanges are taken
 * newest-first and the oldest are dropped until the window fits, and the
 * caller records a `context_trim` when that happened. This is still
 * deterministic static trimming — no LLM summarization, which
 * DESIGN_CONSIDERATIONS §2.3 rejects as silently lossy — and it still fails
 * closed: if even a head-only window does not fit, the run is
 * `context_exhausted` exactly as before. What changes is that a run which
 * used to die on its own tail now continues with a shorter, honest one.
 *
 * The prompt head is the one thing the budget may refuse to fit: it is the
 * run's constitution and is never truncated. A head that does not fit is a
 * real failure (a task brief too large for the model) and is reported as
 * such rather than silently mangled.
 *
 * **The session memory block is part of the fixed cost, since build order
 * step 2** (`docs/design/SESSION_TASKS.md` §4). A session run's
 * `## Memories` block is rebuilt from the store on every request and rides
 * between the head and the kept exchanges — the first mutable, per-request
 * section a run request has ever had. It is bounded separately
 * ({@see sessionMemoryBudgetChars()}) and counted here like the head: it is
 * not an exchange, so the adaptive trim cannot shed it piece by piece, and
 * the fit must account for it up front. A request whose head, tool
 * definitions, and memory block alone cannot fit still fails closed.
 *
 * **The Inputs block is capped, since it cannot be pruned.** A head is
 * usually bounded — the brief, the toolbox, the grounding — but the Inputs
 * block (SPEC §13.4) grows with the task's width, and the final consumer
 * receives *every* step output. Because the head is never pruned, an
 * unbounded Inputs block is an unbounded floor on every request the run
 * makes, which is the same failure the tool-result cap prevents on the other
 * side of the head. So each artifact is capped to a per-input share of
 * {@see maxInputArtifactPct} (one input gets the whole allowance, two get
 * half each, and so on), keeping the block's total under the knob whatever
 * the fan-in. See {@see capInputArtifact()}.
 *
 * Token estimates are deliberately conservative and shared
 * ({@see TokenEstimate}). The number is a guard rail, not a billing meter:
 * under-counting would let a request exceed the model's window on the wire,
 * where the failure is a hard provider error rather than our clean
 * fail-closed one, so the estimate errs toward declaring exhaustion early.
 * Tool definitions are counted too — they travel on every request and were
 * previously invisible to this check (see {@see buildMessages()}).
 */
final readonly class ContextWindow
{
    private const float CHARS_PER_TOKEN = TokenEstimate::CHARS_PER_TOKEN;
    private const string TRUNCATED_MARKER = '…[truncated]';
    private const string INPUT_TRUNCATED_MARKER = '…[truncated — input capped]';

    public function __construct(
        private int $contextLimitTokens,
        private float $maxToolOutputPct = 15.0,
        private int $windowTailExchanges = 10,
        private float $maxInputArtifactPct = 50.0,
        private float $maxSessionMemoryPct = 10.0,
        private float $contextTrimTargetPct = 50.0,
    ) {
    }

    /**
     * The character budget of a rendered `## Memories` block.
     *
     * Its own percentage, deliberately independent of the input-artifact cap
     * (SESSION_TASKS.md §3.3): memory and inputs are different kinds of
     * never-pruned content with different justifications (state vs.
     * dependency outputs), so tying them to one figure would mean tuning one
     * to fix the other. The renderer
     * ({@see \App\Session\SessionMemoryRenderer}) enforces this by dropping
     * the oldest notes whole; the objective is never dropped.
     */
    public function sessionMemoryBudgetChars(): int
    {
        return (int) floor($this->contextLimitTokens * self::CHARS_PER_TOKEN * $this->maxSessionMemoryPct / 100);
    }

    /**
     * Cap a tool result to max_tool_output_percentage of the context limit.
     *
     * Data, not instructions: the stored value is whatever the server
     * returned; this is only about what goes back to the model.
     */
    public function capToolResult(string $result): string
    {
        $maxChars = (int) floor($this->contextLimitTokens * self::CHARS_PER_TOKEN * $this->maxToolOutputPct / 100);

        if (\strlen($result) <= $maxChars) {
            return $result;
        }

        // Byte budget, character-safe cut: a byte offset landing mid-character
        // would leave a partial UTF-8 sequence, and json_encode (and so every
        // `json` column this result is later written to) refuses those.
        return Utf8::cut($result, $maxChars)."\n".self::TRUNCATED_MARKER;
    }

    /**
     * Cap one step-input artifact carried in an Inputs block (SPEC §13.4).
     *
     * $inputCount is how many inputs compete for the budget; each gets
     * `budget / $inputCount`, so the block's total stays under the knob
     * whatever the fan-in. The cap is on the artifact text only — the
     * `## Inputs` framing and the step-title labels are the harness's and are
     * not counted against it.
     *
     * When the artifact does not fit, it is truncated with a marker that says
     * so: the model — and a human reading the transcript — must be able to
     * tell a trimmed input from a short one. Data, not instructions: the
     * stored artifact is untouched, only the copy sent to the model is
     * bounded.
     */
    public function capInputArtifact(string $artifact, int $inputCount): string
    {
        $perInputChars = (int) floor($this->contextLimitTokens * self::CHARS_PER_TOKEN * $this->maxInputArtifactPct / 100 / max(1, $inputCount));

        if (\strlen($artifact) <= $perInputChars) {
            return $artifact;
        }

        return Utf8::cut($artifact, $perInputChars)."\n".self::INPUT_TRUNCATED_MARKER;
    }

    /**
     * Build the message list sent to the model: prompt head (never pruned)
     * + as many of the newest tool-call exchanges as the budget allows.
     * Fails closed with an exception if even the head + tool definitions
     * alone cannot fit.
     *
     * **The drop is a whole exchange.** The unit retired is one assistant
     * turn together with its tool results, never a single message: a `tool`
     * message with no preceding `tool_calls` is a malformed request, so the
     * conversation has to stay whole.
     *
     * **The front is sticky when a plan is supplied** ({@see ShedPlan}). The
     * plan's `base` fixes the oldest exchange that may travel; the candidates
     * are `exchanges[base ..]`, so between sheds the front stands still and
     * the prefix the server already prefilled is reused (docs/design/
     * KV_CACHE.md §6.2, fixing B2). Without a plan the window keeps its own
     * stateless bound — the newest `window_tail_exchanges`, whose front
     * advances every turn once full. The plan's `batch` flag sheds a whole
     * batch to the headroom target when the budget forces anything out,
     * rather than stopping at the newest exchange that fits: one re-prefill,
     * then a long stable stretch, instead of a trim every turn.
     *
     * **The batch target is a fraction of the whole request budget** — head,
     * tool definitions, session memory, and the retained tail together — not
     * a fraction of the tail. A trim costs the *retained* tail, so a
     * budget-relative target lands under the limit whatever a single large
     * result did to the window, where "half the tail" could still land near
     * full and trim again next turn.
     *
     * @param array{system: string, user: string} $promptHead
     * @param list<array<string, mixed>>          $exchanges     completed exchanges, oldest first
     * @param list<array<string, mixed>>          $tools         OpenAI tool descriptors sent on the same request
     * @param ?string                             $sessionMemory the rendered `## Memories` block for a
     *                                                           session run, or null for an ordinary run — it rides
     *                                                           after the head, before the kept exchanges
     * @param ?ShedPlan                           $plan          the caller-owned sticky base and batch flag, or
     *                                                           null to keep the window's own stateless count bound
     *
     * @throws ContextExhaustedException when the head + tool definitions + session memory alone exceed the budget
     */
    public function buildMessages(array $promptHead, array $exchanges, array $tools = [], ?string $sessionMemory = null, ?ShedPlan $plan = null): ContextWindowResult
    {
        $memoryBlock = null !== $sessionMemory && '' !== trim($sessionMemory) ? $sessionMemory : null;

        // The fixed cost of every request: the head, the tool definitions
        // that travel beside the messages, and — for a session run — the
        // memory block, which is rebuilt per request but is not an exchange
        // and therefore cannot be shed piece by piece. All count: the
        // estimate must describe the request, not just its message array.
        $headMessages = [
            ['role' => 'system', 'content' => $promptHead['system']],
            ['role' => 'user', 'content' => $promptHead['user']],
        ];

        if (null !== $memoryBlock) {
            $headMessages[] = ['role' => 'assistant', 'content' => $memoryBlock];
        }

        $fixedChars = $this->messagesChars($headMessages) + \strlen((string) json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($this->tokensFor($fixedChars) > $this->contextLimitTokens) {
            $fixed = null === $memoryBlock
                ? 'the prompt head and tool definitions'
                : 'the prompt head, tool definitions, and session memory block';

            throw new ContextExhaustedException(\sprintf('context exhausted: %s alone are an estimated %d tokens > limit %d (the head is never truncated)', $fixed, $this->tokensFor($fixedChars), $this->contextLimitTokens));
        }

        // The front: a plan's sticky base when one is supplied, else the
        // stateless newest-N bound. `base` is clamped into the list, and the
        // newest-N slice rides on top of it so an oversized base (a
        // checkpoint from before a knob change) still yields a bounded,
        // well-formed window rather than every exchange ever seen.
        // Without a plan: the stateless newest-N bound, whose front advances
        // every turn once full (B2 — preserved for callers that pass no plan).
        // With a plan: every exchange from the sticky base forward, kept whole
        // until the budget forces a batch shed, so the front stands still and
        // the retained set grows append-only (docs/design/KV_CACHE.md §6.2).
        $candidates = null === $plan
            ? \array_slice($exchanges, -$this->windowTailExchanges)
            : \array_slice($exchanges, max(0, min($plan->base ?? 0, \count($exchanges))));

        // Price each candidate once, oldest first, so the shed loop below is
        // arithmetic rather than repeated encoding.
        $costs = [];
        $messages = [];
        foreach ($candidates as $candidate) {
            $rendered = $this->exchangeToMessages($candidate);
            $messages[] = $rendered;
            $costs[] = $this->tokensFor($this->messagesChars($rendered));
        }

        $count = \count($candidates);
        $fixedTokens = $this->tokensFor($fixedChars);

        // How many newest whole exchanges fit, oldest dropped. The drop is a
        // whole exchange (see the method note), taken one at a time so the
        // non-batch path keeps its exact "stop at the newest exchange that
        // fits" behavior for every pre-existing caller.
        $keptExchanges = 0;
        $tokens = $fixedTokens;
        for ($index = $count - 1; $index >= 0; --$index) {
            if ($tokens + $costs[$index] > $this->contextLimitTokens) {
                break;
            }

            $tokens += $costs[$index];
            ++$keptExchanges;
        }

        // Batched shedding: once this fit has been forced to shed anything,
        // keep shedding down to the headroom target — one re-prefill now, a
        // long stable stretch after, instead of a trim every turn. Never
        // sheds everything: the newest exchange is the loop's resume point
        // and stays, exactly as the count bound keeps it.
        if (null !== $plan && $plan->batch && $keptExchanges < $count) {
            $target = $this->trimTargetTokens();

            while ($keptExchanges > 1 && $tokens > $target) {
                --$keptExchanges;
                $tokens -= $costs[$count - $keptExchanges - 1];
            }
        }

        $keptMessages = [];
        for ($offset = $count - $keptExchanges; $offset < $count; ++$offset) {
            foreach ($messages[$offset] as $message) {
                $keptMessages[] = $message;
            }
        }

        return new ContextWindowResult(
            messages: [...$headMessages, ...$keptMessages],
            keptExchanges: $keptExchanges,
            droppedExchanges: $count - $keptExchanges,
            estimatedTokens: $tokens,
            limitTokens: $this->contextLimitTokens,
        );
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
     * The approximate character cost of a message list: every message's
     * text content, plus the encoded arguments of any tool calls it
     * carries (those travel on the wire too, and were previously uncounted).
     *
     * @param list<array<string, mixed>> $messages
     */
    private function messagesChars(array $messages): int
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

    /**
     * @param array<string, mixed> $exchange {assistant: array, toolResults: list<array>}
     *
     * @return list<array<string, mixed>>
     */
    private function exchangeToMessages(array $exchange): array
    {
        $messages = [];

        $assistant = $exchange['assistant'] ?? null;
        if (\is_array($assistant)) {
            // Never re-send prior reasoning (SPEC §5.6).
            $assistantMessage = [
                'role' => 'assistant',
                'content' => \is_string($assistant['content'] ?? null) ? $assistant['content'] : null,
            ];
            $toolCalls = $assistant['toolCalls'] ?? [];
            if ([] !== $toolCalls) {
                $assistantMessage['tool_calls'] = array_map(
                    /** @param array<string, mixed> $call */
                    static fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => ['name' => $call['name'], 'arguments' => self::encodeArguments($call['arguments'])],
                    ],
                    $toolCalls,
                );
            }
            $messages[] = $assistantMessage;
        }

        foreach (($exchange['toolResults'] ?? []) as $result) {
            if (!\is_array($result)) {
                continue;
            }
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $result['toolCallId'],
                'content' => $result['content'],
            ];
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function encodeArguments(array $arguments): string
    {
        if ([] === $arguments) {
            return '{}';
        }

        return (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
