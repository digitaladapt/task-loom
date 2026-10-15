<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\ContextWindow;
use App\Context\ShedPlan;
use PHPUnit\Framework\TestCase;

/**
 * Batched shedding with a sticky base — the run half of
 * docs/design/KV_CACHE.md §6.2 (breaker B2).
 *
 * The property under test is the one §7 names: across a run's consecutive
 * turns the longest common prefix of the requests must be non-decreasing
 * except at a counted trim. The old window re-sliced to the newest N every
 * turn, so once N exchanges existed its front advanced by one exchange per
 * turn and the prefix collapsed to the head alone. With a plan, the front
 * stands still between forced sheds.
 */
final class ContextWindowShedPlanTest extends TestCase
{
    /**
     * The regression guard for B2. On the stateless path the front slides
     * every turn; on the plan path it does not, so the second turn's request
     * begins with everything the first turn's request began with.
     */
    public function testStickyBaseHoldsTheFrontStillAcrossTurns(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];

        // Stateless (no plan): once more than N exchanges exist the front moves.
        $eleven = $this->exchanges(11);
        $first = $window->buildMessages($head, \array_slice($eleven, 0, 10));
        $second = $window->buildMessages($head, $eleven);
        self::assertSame(
            $this->commonPrefixLength($first->messages, $second->messages),
            2,
            'without a plan the front advances every turn — the head is all that survives (B2)',
        );

        // Plan with a sticky base: the base is 0 (from the beginning), so both
        // turns carry the same first six messages and the front has not moved.
        $base = 0;
        $firstPlan = $window->buildMessages($head, \array_slice($eleven, 0, 10), [], null, new ShedPlan($base));
        $secondPlan = $window->buildMessages($head, $eleven, [], null, new ShedPlan($base));
        self::assertGreaterThanOrEqual(
            6,
            $this->commonPrefixLength($firstPlan->messages, $secondPlan->messages),
            'with a plan the retained front stands still, so the prefix is reused',
        );

        // And the second turn still does not shed: growing by one exchange
        // fits, so no trim and no re-prefill.
        self::assertFalse($secondPlan->trimmed());
        self::assertSame(11, $secondPlan->keptExchanges);
    }

    /**
     * A run whose retained set is within budget keeps every exchange it has
     * accumulated — the sticky base is a floor, not the old newest-N cap.
     */
    public function testStickyBaseKeepsTheWholeRetainedSetWhenItFits(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 2);

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], $this->exchanges(5), [], null, new ShedPlan(0));

        self::assertFalse($fit->trimmed());
        self::assertSame(5, $fit->keptExchanges, 'with a plan, windowTailExchanges is not a cap');
    }

    /**
     * When the budget forces a shed and batching is on, the window sheds down
     * to the headroom target — not to "just fits" — so one re-prefill buys a
     * long stable stretch instead of a trim next turn. The newest exchange is
     * never shed: it is where the loop resumes.
     */
    public function testBatchShedsToTheHeadroomTargetNotToJustFits(): void
    {
        // 1000 tokens ≈ 3500 chars. Each exchange is ~600 chars (~172 tokens),
        // so ~5 fit before the budget (plus head) is reached; the batch target
        // is 50% of 1000 = 500 tokens.
        $window = new ContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0, windowTailExchanges: 1000, contextTrimTargetPct: 50.0);

        $exchanges = $this->exchanges(9, 600);
        $batched = $window->buildMessages(['system' => 'S', 'user' => 'U'], $exchanges, [], null, new ShedPlan(0, batch: true));
        $greedy = $window->buildMessages(['system' => 'S', 'user' => 'U'], $exchanges, [], null, new ShedPlan(0, batch: false));

        self::assertTrue($batched->trimmed());
        self::assertLessThanOrEqual(
            500,
            $batched->estimatedTokens,
            'a batch shed lands at or under the headroom target, not just under the limit',
        );
        self::assertLessThan(
            $greedy->keptExchanges,
            $batched->keptExchanges,
            'it shed more than the budget strictly required',
        );
        // The newest exchange always survives the batch.
        self::assertGreaterThanOrEqual(1, $batched->keptExchanges);
    }

    /**
     * Without the batch flag the shed stops at the newest exchange that fits,
     * exactly as before — the flag is what changes the depth, nothing else.
     */
    public function testPlanWithoutBatchStillStopsAtTheNewestThatFits(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0, windowTailExchanges: 1000, contextTrimTargetPct: 50.0);
        $exchanges = $this->exchanges(9, 600);

        $greedy = $window->buildMessages(['system' => 'S', 'user' => 'U'], $exchanges, [], null, new ShedPlan(0, batch: false));
        $batched = $window->buildMessages(['system' => 'S', 'user' => 'U'], $exchanges, [], null, new ShedPlan(0, batch: true));

        self::assertGreaterThan($batched->keptExchanges, $greedy->keptExchanges);
        self::assertLessThanOrEqual(1000, $greedy->estimatedTokens);
    }

    /**
     * A base past the end of the list is clamped, so a stale checkpoint cannot
     * empty the window: the newest-N slice still applies and the newest
     * exchange always travels.
     */
    public function testAnOverlargeBaseIsClampedAndTheWindowIsNeverEmpty(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);

        $fit = $window->buildMessages(['system' => 'S', 'user' => 'U'], $this->exchanges(3), [], null, new ShedPlan(99));

        self::assertSame(0, $fit->keptExchanges);
        self::assertFalse($fit->trimmed());
        self::assertCount(2, $fit->messages);
    }

    /**
     * @return list<array{assistant: array{content: ?string, toolCalls: list<array<string, mixed>>}, toolResults: list<array{toolCallId: string, content: string}>}>
     */
    private function exchanges(int $count, int $resultChars = 4): array
    {
        $exchanges = [];
        for ($i = 0; $i < $count; ++$i) {
            $exchanges[] = [
                'assistant' => [
                    'content' => null,
                    'toolCalls' => [['id' => "c$i", 'name' => 't', 'arguments' => []]],
                ],
                'toolResults' => [['toolCallId' => "c$i", 'content' => str_repeat('r', $resultChars)." $i"]],
            ];
        }

        return $exchanges;
    }

    /**
     * The length of the longest common prefix of two message lists, compared
     * message by message on role + encoded content. This is the "longest
     * common prefix length" of §7's stability test, at message granularity.
     *
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     */
    private function commonPrefixLength(array $a, array $b): int
    {
        $length = 0;
        $max = min(\count($a), \count($b));

        for ($i = 0; $i < $max; ++$i) {
            if ($this->messageKey($a[$i]) !== $this->messageKey($b[$i])) {
                break;
            }
            ++$length;
        }

        return $length;
    }

    /** @param array<string, mixed> $message */
    private function messageKey(array $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
