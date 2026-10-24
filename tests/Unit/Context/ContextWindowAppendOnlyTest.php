<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\ContextWindow;
use App\Context\ShedPlan;
use PHPUnit\Framework\TestCase;

/**
 * The invariant docs/design/THE_STREAM.md §2.3 states as a testable rule:
 *
 * > Between trims, the wire context changes only by appending.
 *
 * "Only by appending" is stronger than "the front stands still". It says each
 * request carries every message the one before it carried, in the same order,
 * byte for byte — so the server's prefix cache is extended rather than
 * invalidated, and a turn that adds a tool result costs the result and not the
 * conversation. {@see ContextWindowShedPlanTest} measures the *length* of the
 * shared prefix, which passes while a head is quietly reworded; comparing the
 * message lists outright is what fails.
 *
 * The two ways this breaks in practice are both historical, and both are
 * asserted here so they cannot come back:
 *
 * - the front sliding forward every turn (the stateless newest-N bound, B2);
 * - the head moving between trims, which moves the trim point with it (§2.3.3).
 *
 * Everything below runs with the deliberate shed path (a `ShedPlan`) and a
 * budget large enough that nothing ever sheds. A test that trimmed would be
 * asserting the exception, not the rule.
 */
final class ContextWindowAppendOnlyTest extends TestCase
{
    /**
     * The rule itself: N turns, no trim, and every request extends the last.
     */
    public function testEachRequestExtendsThePreviousWhenNothingSheds(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1_000_000, maxToolOutputPct: 15.0, windowTailExchanges: 5);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];

        $previous = null;
        $previousCount = 0;

        // More turns than windowTailExchanges, so a stateless window would
        // already have started sliding its front by the end of the run.
        for ($turn = 1; $turn <= 12; ++$turn) {
            $exchanges = $this->exchanges($turn);
            $result = $window->buildMessages($head, $exchanges, [], null, new ShedPlan(0));

            self::assertFalse(
                $result->trimmed(),
                \sprintf('turn %d must not trim: nothing in this test approaches the budget', $turn),
            );

            if (null !== $previous) {
                self::assertSame(
                    $previous,
                    \array_slice($result->messages, 0, \count($previous)),
                    \sprintf(
                        'turn %d must carry every message turn %d carried, unchanged and in order — the context changes only by appending',
                        $turn,
                        $turn - 1,
                    ),
                );

                self::assertGreaterThan(
                    $previousCount,
                    \count($result->messages),
                    \sprintf('turn %d must add at least the turn it answers', $turn),
                );
            }

            $previous = $result->messages;
            $previousCount = \count($result->messages);
        }
    }

    /**
     * The control, so the rule above cannot quietly become vacuous.
     *
     * The first test is only evidence while a window *without* a plan still
     * breaks the invariant — otherwise it would pass for a window that always
     * appends, which would tell us nothing about the plan path it is really
     * guarding. Today the stateless newest-N bound slides its front the moment
     * the exchanges outnumber `windowTailExchanges`; this asserts that, and
     * names the exact turn it starts, because that turn is the shape of the
     * bug: the front moving, not the head changing.
     *
     * If this ever fails, the invariant has become unconditional and this test
     * should be deleted rather than repaired — a control that no longer
     * discriminates is not a control.
     */
    public function testWithoutAPlanTheFrontSlidesAndTheInvariantBreaks(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1_000_000, maxToolOutputPct: 15.0, windowTailExchanges: 5);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];

        $previous = null;
        $brokeAt = null;

        for ($turn = 1; $turn <= 12 && null === $brokeAt; ++$turn) {
            $messages = $window->buildMessages($head, $this->exchanges($turn))->messages;

            if (null !== $previous && $previous !== \array_slice($messages, 0, \count($previous))) {
                $brokeAt = $turn;
            }

            $previous = $messages;
        }

        self::assertSame(
            6,
            $brokeAt,
            'the stateless window slides its front at turn 6 — one past windowTailExchanges=5 — which is what makes the planned path above meaningful',
        );
    }

    /**
     * The head is where the invariant is most likely to die quietly, and the
     * one place §2.3 names as its own destroyer: a head rebuilt per request
     * with a fresh grounding timestamp is a different string every time, so
     * *nothing* is reused even though no message was dropped. Pinned because
     * §2.2's decision — compile the head once per being — is the fix, and this
     * is the test that will notice if a future compile starts varying again.
     */
    public function testTheHeadIsByteIdenticalBetweenRequests(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1_000_000, maxToolOutputPct: 15.0, windowTailExchanges: 5);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];
        $exchanges = $this->exchanges(3);

        $first = $window->buildMessages($head, \array_slice($exchanges, 0, 2), [], null, new ShedPlan(0));
        $second = $window->buildMessages($head, $exchanges, [], null, new ShedPlan(0));

        self::assertSame($first->messages[0], $second->messages[0], 'the system head must not move between requests');
        self::assertSame($first->messages[1], $second->messages[1], 'the task prompt must not move between requests');
    }

    /**
     * And the other destroyer, from the render side: a tool result stored whole
     * must render the same on the turn it arrived and on every later turn it
     * is still in the window. A cap computed from the *retained size* would
     * re-render the same row shorter as history grew, silently rewriting the
     * prefix — the failure §2.3.2 describes. This pins the frozen basis: the
     * same row keeps one rendering while the window grows around it.
     */
    public function testAStoredResultRendersTheSameAsHistoryGrows(): void
    {
        $window = new ContextWindow(contextLimitTokens: 1_000_000, maxToolOutputPct: 15.0, windowTailExchanges: 5);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];

        // One oversized result, then more conversation after it.
        $exchanges = $this->exchanges(6, 400_000);

        $early = $window->buildMessages($head, \array_slice($exchanges, 0, 2), [], null, new ShedPlan(0));
        $later = $window->buildMessages($head, $exchanges, [], null, new ShedPlan(0));

        $renderedEarly = $this->toolMessages($early->messages);
        $renderedLater = $this->toolMessages($later->messages);

        self::assertNotEmpty($renderedEarly, 'the oversized result must be present to compare');
        self::assertSame(
            $renderedEarly,
            \array_slice($renderedLater, 0, \count($renderedEarly)),
            'a stored result must render identically forever: the cap comes from a frozen basis, not from the window size',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exchanges(int $count, int $resultChars = 8): array
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
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function toolMessages(array $messages): array
    {
        return array_values(array_filter($messages, static fn (array $m): bool => 'tool' === ($m['role'] ?? null)));
    }
}
