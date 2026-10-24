<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\ChatContextWindow;
use App\Chat\ChatWireWindow;
use App\Context\ShedPlan;
use App\Entity\ChatEventType;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatOrigin;
use App\Entity\LedgerEvent;
use App\Entity\Participant;
use App\Entity\TurnRole;
use PHPUnit\Framework\TestCase;

/**
 * Batched shedding with a sticky front — the chat half of
 * docs/design/KV_CACHE.md §6.2 (breaker B2).
 *
 * The chat front is anchored by a **wire event id**, not an index, because a
 * conversation's read window slides as the transcript grows. The property is
 * the same one the run window holds: between forced sheds the front stands
 * still, so the prefix the server already prefilled is reused; and a shed
 * drops a whole batch to the headroom target, so one re-prefill buys several
 * stable turns instead of a trim every turn.
 */
final class ChatContextWindowShedPlanTest extends TestCase
{
    /**
     * The B2 fix, chat side. Without a plan the oldest turn is shed every time
     * the transcript grows past the budget, so the front advances every turn.
     * With a plan and batching, the first forced shed drops to the headroom
     * target and the following turns shed nothing — the front is still.
     */
    public function testABatchShedBuysSeveralStableTurns(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 1000, windowTurns: 100, contextTrimTargetPct: 50.0);

        // Turn A: nine turns overflow the budget, so a batch shed fires.
        $a = $window->fit(new ChatWireWindow($this->turns(9, 500)), 'SYS', [], new ShedPlan(null, batch: true));
        self::assertTrue($a->trimmed());
        $base = $a->contextBaseId;
        self::assertNotNull($base);
        self::assertLessThan(9, $a->keptTurns, 'the batch shed kept well under the whole transcript');

        // Turn B: one more turn, re-anchored to A's base. The retained stretch
        // still fits, so nothing is shed and the front does not move.
        $b = $window->fit(new ChatWireWindow($this->turns(10, 500)), 'SYS', [], new ShedPlan($base, batch: true));
        self::assertFalse($b->trimmed());
        self::assertSame($base, $b->contextBaseId, 'the front stands still between sheds (B2 fixed)');
        self::assertGreaterThan($a->keptTurns, $b->keptTurns);

        // Turn C: still no shed, still the same front.
        $c = $window->fit(new ChatWireWindow($this->turns(11, 500)), 'SYS', [], new ShedPlan($base, batch: true));
        self::assertFalse($c->trimmed());
        self::assertSame($base, $c->contextBaseId);
    }

    /**
     * Without a plan the stateless behavior is preserved: the shed deepens by
     * a turn each time the transcript grows past the budget, which is the
     * sliding front B2 describes.
     */
    public function testWithoutAPlanTheFrontStillSlides(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 1000, windowTurns: 100, contextTrimTargetPct: 50.0);

        $a = $window->fit(new ChatWireWindow($this->turns(9, 500)), 'SYS');
        $b = $window->fit(new ChatWireWindow($this->turns(10, 500)), 'SYS');

        self::assertTrue($a->trimmed());
        self::assertTrue($b->trimmed());
        self::assertGreaterThan($a->droppedTurns, $b->droppedTurns, 'each grown turn sheds one more — the slide');
    }

    /**
     * The question is never shed by a batch, even when it sits behind newer
     * tool rounds — a request with no user message is refused by the endpoint.
     */
    public function testTheQuestionSurvivesABatchShedBehindNewerRounds(): void
    {
        // The cap is pinned high so it does not touch these fixtures: what is
        // under test is which rounds a batch shed takes, not how much of a
        // result survives to be shed. At the default 15% a 1500-char result
        // caps to ~525 bytes and the transcript never overflows at all.
        $window = new ChatContextWindow(contextLimitTokens: 1000, windowTurns: 100, contextTrimTargetPct: 50.0, maxToolOutputPct: 100.0);

        // [Q1] [round] [Q2 ← the question] [round] [round] ...
        $rows = [
            $this->userTurn('QUESTION-ONE', 1),
            ...$this->round('a', 2, 3),
            $this->userTurn('QUESTION-TWO-IS-THE-QUESTION', 4),
            ...$this->round('b', 5, 6),
            ...$this->round('c', 7, 8),
            ...$this->round('d', 9, 10),
            ...$this->round('e', 11, 12),
        ];

        $fit = $window->fit(new ChatWireWindow($rows), 'SYS', [], new ShedPlan(null, batch: true));

        self::assertTrue($fit->trimmed());
        $contents = array_column($fit->messages, 'content');
        self::assertContains('QUESTION-TWO-IS-THE-QUESTION', $contents, 'the question rides whatever the batch does');
    }

    /**
     * A base id the read no longer carries (evicted by the turn bound) means
     * "from the head", not "empty": the newest stretch still travels.
     */
    public function testAnEvictedBaseFallsBackToTheHead(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 100_000, windowTurns: 100);

        $fit = $window->fit(new ChatWireWindow($this->turns(3, 20)), 'SYS', [], new ShedPlan(9999, batch: true));

        self::assertFalse($fit->trimmed());
        self::assertSame(3, $fit->keptTurns);
        self::assertSame(1, $fit->contextBaseId, 'the front is reported at the head, ready for the next turn');
    }

    /**
     * @return list<ChatExchangeEvent>
     */
    private function turns(int $count, int $chars): array
    {
        $turns = [];
        for ($i = 1; $i <= $count; ++$i) {
            $turns[] = $this->userTurn(str_repeat('t', $chars)." $i", $i);
        }

        return $turns;
    }

    private function userTurn(string $content, int $id): ChatExchangeEvent
    {
        return $this->withId(ChatExchangeEvent::turn(
            ChatEventType::Message,
            Participant::Andrew,
            TurnRole::User,
            ChatOrigin::Web,
            $content,
        ), $id);
    }

    /** @return list<ChatExchangeEvent> a whole tool round: the call and one result */
    private function round(string $key, int $callId, int $resultId): array
    {
        $call = new ChatExchangeEvent(ChatEventType::ToolCall);
        $call->setPayload([
            'calls' => [['id' => 'call-'.$key, 'name' => 'get_weather', 'arguments' => []]],
            'assistantContent' => null,
        ]);

        $result = new ChatExchangeEvent(ChatEventType::ToolResult);
        $result->setPayload(['tool' => 'get_weather', 'content' => str_repeat('r', 1500), 'isError' => false, 'toolCallId' => 'call-'.$key]);

        return [$this->withId($call, $callId), $this->withId($result, $resultId)];
    }

    /**
     * Give an unpersisted event the id the anchor arithmetic reads. A real
     * read always persists its rows first, so this only exists to let the unit
     * test drive ids the way a fetch would.
     */
    private function withId(ChatExchangeEvent $event, int $id): ChatExchangeEvent
    {
        $property = new \ReflectionProperty(LedgerEvent::class, 'id');
        $property->setValue($event, $id);

        return $event;
    }
}
