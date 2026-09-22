<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\ContextExhaustedException;
use App\Context\ContextWindow;
use App\Context\Grounding;
use PHPUnit\Framework\TestCase;

/**
 * ContextWindow: tool-output capping, tail-window trimming, the
 * never-prune-the-prompt-head guarantee (locked decision 4), and the
 * fail-closed budget.
 */
final class ContextWindowTest extends TestCase
{
    public function testCapsToolResultToBudget(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $capped = $window->capToolResult(str_repeat('x', 100 * 1024));

        self::assertLessThanOrEqual(1000 * 4 * 0.15 + 20, \strlen($capped));
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
            $exchanges[] = [
                'assistant' => ['content' => null, 'toolCalls' => [['id' => "c$i", 'name' => 't', 'arguments' => []]]],
                'toolResults' => [['toolCallId' => "c$i", 'content' => "result $i"]],
            ];
        }

        $messages = $window->buildMessages(['system' => 'SYS', 'user' => 'TASK PROMPT'], $exchanges);

        // head + tail(2) exchanges = 2 + 2*2 = 6 messages
        self::assertCount(6, $messages);
        self::assertSame('SYS', $messages[0]['content']);
        self::assertSame('TASK PROMPT', $messages[1]['content']);
        // only the last 2 exchanges' results are present
        $contents = array_column($messages, 'content');
        self::assertContains('result 3', $contents);
        self::assertContains('result 4', $contents);
        self::assertNotContains('result 0', $contents);
    }

    public function testReasoningNeverResent(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $messages = $window->buildMessages(['system' => 'S', 'user' => 'U'], [
            [
                'assistant' => ['content' => 'answer text', 'toolCalls' => []],
                'toolResults' => [],
            ],
        ]);

        $encoded = json_encode($messages) ?: '';
        self::assertStringNotContainsString('reasoning', $encoded);
        self::assertSame('answer text', $messages[2]['content']);
    }

    public function testToolCallArgumentsReEncodedAsString(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $messages = $window->buildMessages(['system' => 'S', 'user' => 'U'], [
            [
                'assistant' => [
                    'content' => null,
                    'toolCalls' => [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 'Reykjavik']]],
                ],
                'toolResults' => [['toolCallId' => 'c1', 'content' => 'sunny']],
            ],
        ]);

        $assistant = $messages[2];
        self::assertSame('assistant', $assistant['role']);
        self::assertSame('{"location":"Reykjavik"}', $assistant['tool_calls'][0]['function']['arguments']);
        $toolMessage = $messages[3];
        self::assertSame('tool', $toolMessage['role']);
        self::assertSame('c1', $toolMessage['tool_call_id']);
    }

    public function testBudgetExceededFailsClosed(): void
    {
        $window = new ContextWindow(contextLimitTokens: 10, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $this->expectException(ContextExhaustedException::class);

        $window->buildMessages(['system' => \str_repeat('s', 200), 'user' => \str_repeat('u', 200)], []);
    }

    public function testGroundingRenderShape(): void
    {
        $grounding = new Grounding(
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
}
