<?php

declare(strict_types=1);

namespace App\Context;

/**
 * Static context window trimming with a fail-closed budget (SPEC §5.6).
 *
 * The initial user section — the task prompt — is never pruned (locked
 * decision 4). Only excessively old assistant messages from the LLM
 * itself are pruned: prior thinking/reasoning is never re-sent, and only
 * the last `windowTailExchanges` tool-call exchanges are kept after the
 * prompt head.
 *
 * Token estimates are a cheap heuristic (4 chars ≈ 1 token) — the budget
 * check is fail-closed against a configurable limit, never a silent fit.
 */
final readonly class ContextWindow
{
    private const int CHARS_PER_TOKEN = 4;
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
     * + the last N tool-call exchanges. Fails closed with an exception if
     * even the pruned window cannot fit the budget.
     *
     * @param array{system: string, user: string} $promptHead
     * @param list<array<string, mixed>>          $exchanges  completed exchanges
     *
     * @return list<array{role: string, content: ?string, tool_calls?: list<array<string, mixed>>, tool_call_id?: string}>
     *
     * @throws ContextExhaustedException when the pruned window still exceeds the budget
     */
    public function buildMessages(array $promptHead, array $exchanges): array
    {
        $messages = [
            ['role' => 'system', 'content' => $promptHead['system']],
            ['role' => 'user', 'content' => $promptHead['user']],
        ];

        $kept = \array_slice($exchanges, -$this->windowTailExchanges);

        foreach ($kept as $exchange) {
            foreach ($this->exchangeToMessages($exchange) as $message) {
                $messages[] = $message;
            }
        }

        $this->assertWithinBudget($messages);

        return $messages;
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

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function assertWithinBudget(array $messages): void
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += \strlen((string) ($message['content'] ?? ''));
        }

        $tokens = (int) ceil($chars / self::CHARS_PER_TOKEN);

        if ($tokens > $this->contextLimitTokens) {
            throw new ContextExhaustedException(\sprintf('context exhausted: estimated tokens %d > limit %d', $tokens, $this->contextLimitTokens));
        }
    }
}
