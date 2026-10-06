<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\ContextExhaustedException;
use App\Context\ContextWindow;
use App\Context\Grounding;
use PHPUnit\Framework\TestCase;

/**
 * ContextWindow: tool-output capping, the adaptive tail window, the
 * never-prune-the-prompt-head guarantee (locked decision 4), the
 * tool-definition-aware budget, and the fail-closed floor.
 */
final class ContextWindowTest extends TestCase
{
    public function testCapsToolResultToBudget(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $capped = $window->capToolResult(str_repeat('x', 100 * 1024));

        self::assertLessThanOrEqual((int) floor(1000 * 3.5 * 0.15) + 20, \strlen($capped));
        self::assertStringContainsString('[truncated]', $capped);
    }

    public function testShortToolResultUntouched(): void
    {
        $window = new ContextWindow(contextLimitTokens: 10000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        self::assertSame('{"temp": 21}', $window->capToolResult('{"temp": 21}'));
    }

    public function testPromptHeadAlwaysPresentNeverPruned(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 2);

        $exchanges = [];
        for ($i = 0; $i < 5; ++$i) {
            $exchanges[] = $this->exchange("c$i", "result $i");
        }

        $fit = $window->buildMessages(['system' => 'SYS', 'user' => 'TASK PROMPT'], $exchanges);
        $messages = $fit->messages;

        // head + tail(2) exchanges = 2 + 2*2 = 6 messages
        self::assertCount(6, $messages);
        self::assertSame('SYS', $messages[0]['content']);
        self::assertSame('TASK PROMPT', $messages[1]['content']);
        // only the last 2 exchanges' results are present
        $contents = array_column($messages, 'content');
        self::assertContains('result 3', $contents);
        self::assertContains('result 4', $contents);
        self::assertNotContains('result 0', $contents);
        // The fixed window did the pruning here, not the budget.
        self::assertFalse($fit->trimmed());
    }

    public function testReasoningNeverResent(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], [
            [
                'assistant' => ['content' => 'answer text', 'toolCalls' => []],
                'toolResults' => [],
            ],
        ]);

        $encoded = json_encode($fit->messages) ?: '';
        self::assertStringNotContainsString('reasoning', $encoded);
        self::assertSame('answer text', $fit->messages[2]['content']);
    }

    public function testToolCallArgumentsReEncodedAsString(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], [
            [
                'assistant' => [
                    'content' => null,
                    'toolCalls' => [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 'Reykjavik']]],
                ],
                'toolResults' => [['toolCallId' => 'c1', 'content' => 'sunny']],
            ],
        ]);

        $assistant = $fit->messages[2];
        self::assertSame('assistant', $assistant['role']);
        self::assertSame('{"location":"Reykjavik"}', $assistant['tool_calls'][0]['function']['arguments']);
        $toolMessage = $fit->messages[3];
        self::assertSame('tool', $toolMessage['role']);
        self::assertSame('c1', $toolMessage['tool_call_id']);
    }

    /**
     * The bug this fixes: a run whose tail is large but whose every
     * exchange is individually within budget. The fixed window used to
     * slice the last N and throw; now the oldest whole exchanges are shed
     * until it fits, and the run continues.
     */
    public function testAdaptiveTrimDropsOldestWholeExchangesToFit(): void
    {
        // 1000 tokens ≈ 3500 chars. Head is 2 chars; each exchange ~1500
        // chars (~429 tokens), so two fit and the third does not.
        $window = new ContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], [
            $this->exchange('c_old', str_repeat('a', 1500)),
            $this->exchange('c_mid', str_repeat('b', 1500)),
            $this->exchange('c_new', str_repeat('c', 1500)),
        ]);

        self::assertTrue($fit->trimmed());
        self::assertSame(2, $fit->keptExchanges);
        self::assertSame(1, $fit->droppedExchanges);
        self::assertSame(1000, $fit->limitTokens);
        self::assertLessThanOrEqual(1000, $fit->estimatedTokens);

        $contents = array_column($fit->messages, 'content');
        self::assertNotContains(str_repeat('a', 1500), $contents, 'the oldest exchange is shed first');
        self::assertContains(str_repeat('b', 1500), $contents);
        self::assertContains(str_repeat('c', 1500), $contents);
        // The conversation stays whole: the oldest kept exchange's
        // assistant turn and its tool result are both present and adjacent.
        self::assertSame('c_mid', $fit->messages[2]['tool_calls'][0]['id']);
        self::assertSame('c_mid', $fit->messages[3]['tool_call_id']);
    }

    /**
     * Tool definitions travel on the same request as the messages, so a
     * large toolbox must be able to push the window into a trim — the
     * budget describes the request, not just its message array.
     */
    public function testToolDefinitionsCountTowardsTheBudget(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        // ~3000 chars of tool definitions (~857 tokens) leave room for one
        // small exchange but not two.
        $tools = [['type' => 'function', 'function' => ['name' => 'big', 'description' => str_repeat('d', 2900)]]];

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], [
            $this->exchange('c1', str_repeat('a', 400)),
            $this->exchange('c2', str_repeat('b', 400)),
        ], $tools);

        self::assertTrue($fit->trimmed());
        self::assertSame(1, $fit->keptExchanges);
        self::assertLessThanOrEqual(1000, $fit->estimatedTokens);
    }

    /**
     * The head is the run's constitution: if the head plus the tool
     * definitions cannot fit, the run fails closed rather than sending a
     * mangled prompt.
     */
    public function testHeadPlusToolsOverflowFailsClosed(): void
    {
        $window = new ContextWindow(contextLimitTokens: 10, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $this->expectException(ContextExhaustedException::class);
        $this->expectExceptionMessage('never truncated');

        $window->buildMessages(
            ['system' => \str_repeat('s', 200), 'user' => \str_repeat('u', 200)],
            [],
            [['type' => 'function', 'function' => ['name' => 't', 'description' => 'd']]],
        );
    }

    public function testFitWithNoExchangesIsUntrimmed(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], []);

        self::assertFalse($fit->trimmed());
        self::assertSame(0, $fit->keptExchanges);
        self::assertSame(0, $fit->droppedExchanges);
        self::assertCount(2, $fit->messages);
    }

    public function testGroundingRenderShape(): void
    {
        $grounding = new Grounding(
            timezone: 'UTC',
            location: 'Reykjavik',
            units: 'metric',
            now: new \DateTimeImmutable('2026-03-01 09:15:00', new \DateTimeZone('UTC')),
        );

        $rendered = $grounding->render();

        self::assertStringContainsString('Current date: Sunday, March 1, 2026', $rendered);
        self::assertStringContainsString('Current time: 09:15', $rendered);
        self::assertStringContainsString('Units: metric', $rendered);
        self::assertStringContainsString('Location: Reykjavik', $rendered);
    }

    /**
     * @return array{assistant: array{content: ?string, toolCalls: list<array<string, mixed>>}, toolResults: list<array{toolCallId: string, content: string}>}
     */
    private function exchange(string $id, string $result): array
    {
        return [
            'assistant' => [
                'content' => null,
                'toolCalls' => [['id' => $id, 'name' => 't', 'arguments' => []]],
            ],
            'toolResults' => [['toolCallId' => $id, 'content' => $result]],
        ];
    }
}
