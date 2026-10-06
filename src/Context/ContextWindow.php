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
 * Token estimates are deliberately conservative (3.5 chars ≈ 1 token; see
 * {@see CHARS_PER_TOKEN}). The number is a guard rail, not a billing meter:
 * under-counting would let a request exceed the model's window on the wire,
 * where the failure is a hard provider error rather than our clean
 * fail-closed one, so the estimate errs toward declaring exhaustion early.
 * Tool definitions are counted too — they travel on every request and were
 * previously invisible to this check (see {@see buildMessages()}).
 */
final readonly class ContextWindow
{
    /**
     * Characters per token for the budget estimate.
     *
     * 3.5 rather than the classic 4: this prompt is not prose. It is
     * markdown headers, JSON tool results, key names, uids and other
     * identifiers, all of which the byte-pair tokenizers behind these models
     * split finer than English. Measured against this engine's own compiled
     * output, 4 chars/token under-counts a realistic head by roughly 15–25%,
     * and the check exists to fail early, not to be flattering.
     */
    private const float CHARS_PER_TOKEN = 3.5;
    private const string TRUNCATED_MARKER = '…[truncated]';

    public function __construct(
        private int $contextLimitTokens,
        private float $maxToolOutputPct = 15.0,
        private int $windowTailExchanges = 10,
    ) {
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

        return substr($result, 0, $maxChars)."\n".self::TRUNCATED_MARKER;
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
     * @param array{system: string, user: string} $promptHead
     * @param list<array<string, mixed>>          $exchanges  completed exchanges, oldest first
     * @param list<array<string, mixed>>          $tools      OpenAI tool descriptors sent on the same request
     *
     * @throws ContextExhaustedException when the head + tool definitions alone exceed the budget
     */
    public function buildMessages(array $promptHead, array $exchanges, array $tools = []): ContextWindowResult
    {
        // The fixed cost of every request: the head, plus the tool
        // definitions that travel beside the messages. Both count — the
        // estimate must describe the request, not just its message array.
        $headMessages = [
            ['role' => 'system', 'content' => $promptHead['system']],
            ['role' => 'user', 'content' => $promptHead['user']],
        ];

        $fixedChars = $this->messagesChars($headMessages) + \strlen((string) json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($this->tokensFor($fixedChars) > $this->contextLimitTokens) {
            throw new ContextExhaustedException(\sprintf('context exhausted: the prompt head and tool definitions alone are an estimated %d tokens > limit %d (the head is never truncated)', $this->tokensFor($fixedChars), $this->contextLimitTokens));
        }

        // The candidate tail, newest-first, oldest dropped until it fits.
        $candidates = \array_slice($exchanges, -$this->windowTailExchanges);

        $keptMessages = [];
        $tokens = $this->tokensFor($fixedChars);
        $keptExchanges = 0;

        for ($index = \count($candidates) - 1; $index >= 0; --$index) {
            $exchangeMessages = $this->exchangeToMessages($candidates[$index]);
            $cost = $this->tokensFor($this->messagesChars($exchangeMessages));

            if ($tokens + $cost > $this->contextLimitTokens) {
                break;
            }

            $tokens += $cost;
            $keptMessages = array_merge($exchangeMessages, $keptMessages);
            ++$keptExchanges;
        }

        return new ContextWindowResult(
            messages: [...$headMessages, ...$keptMessages],
            keptExchanges: $keptExchanges,
            droppedExchanges: \count($candidates) - $keptExchanges,
            estimatedTokens: $tokens,
            limitTokens: $this->contextLimitTokens,
        );
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
        return (int) ceil($chars / self::CHARS_PER_TOKEN);
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
