<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\ChatContextWindow;
use App\Chat\ChatWireWindow;
use App\Context\ContextExhaustedException;
use App\Context\Utf8;
use App\Entity\ChatEventType;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatOrigin;
use App\Entity\Participant;
use App\Entity\TurnRole;
use PHPUnit\Framework\TestCase;

/**
 * ChatContextWindow at the unit level (SPEC §15.9): the tool-result cap, the
 * whole-turn shed unit, the question reserved from every shed, the
 * never-trim-the-head / never-drop-the-newest guarantees, and the fail-closed
 * floor.
 *
 * These are unit tests because the properties are about what the fit *can
 * produce* from a given transcript — decidable without a database, a model,
 * or a worker.
 */
final class ChatContextWindowTest extends TestCase
{
    public function testCapsToolResultToBudget(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0);

        $capped = $window->capToolResult(str_repeat('x', 100 * 1024));

        self::assertLessThanOrEqual((int) floor(1000 * 3.5 * 0.15) + 40, \strlen($capped));
        self::assertStringContainsString('[truncated — tool result capped]', $capped);
    }

    /**
     * The exact production failure, end to end at the cap: a tool result thick
     * with multi-byte punctuation, capped at the window default, must remain
     * valid UTF-8 — because it is written to chat_exchange_event.payload (a
     * `json` column) before it is sent anywhere.
     *
     * 1000 tokens × 3.5 chars × 15% = 525 bytes of budget.
     */
    public function testCappedMultiByteToolResultStaysValidUtf8AtEveryBoundary(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 1000, maxToolOutputPct: 15.0);

        foreach (range(520, 530) as $pad) {
            $result = str_repeat('a', $pad).'—·…漢😀'.str_repeat('b', 500);
            $capped = $window->capToolResult($result);

            self::assertTrue(
                Utf8::isValid($capped),
                sprintf('capToolResult produced invalid UTF-8 with pad %d', $pad),
            );
            self::assertNotFalse(
                json_encode(['tool' => 'weather', 'content' => $capped]),
                sprintf('json_encode refused the capped result with pad %d', $pad),
            );
        }
    }

    public function testShortToolResultUntouched(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 10000, maxToolOutputPct: 15.0);

        self::assertSame('{"temp": 21}', $window->capToolResult('{"temp": 21}'));
    }

    /**
     * The head is never trimmed: it rides in front of the fit regardless of
     * how much was shed behind it.
     */
    public function testTheHeadAlwaysTravelsAndNothingIsShedWhenItFits(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 100_000, windowTurns: 100);

        $fit = $window->fit(new ChatWireWindow([$this->turn('morning'), $this->turn('again')]), 'SYS');

        self::assertFalse($fit->trimmed());
        self::assertSame('SYS', $fit->messages[0]['content'] ?? null);
        self::assertSame(2, $fit->keptTurns);
        self::assertSame(0, $fit->droppedTurns);
    }

    /**
     * The shed unit is a whole turn: with a budget that fits the newest turn
     * and the head but not the one before it, the older turn leaves *whole* —
     * and the newest is never the casualty.
     */
    public function testTheOldestTurnsAreShedFirstAndTheNewestSurvives(): void
    {
        // 500 tokens ≈ 1750 chars; the head is tiny, each turn is ~700 chars
        // (~200 tokens), so two fit and the third does not.
        $window = new ChatContextWindow(contextLimitTokens: 500, windowTurns: 100);

        $fit = $window->fit(new ChatWireWindow([
            $this->turn(str_repeat('a', 700)),
            $this->turn(str_repeat('b', 700)),
            $this->turn(str_repeat('c', 700)),
        ]), 'SYS');

        self::assertTrue($fit->trimmed());
        self::assertSame(2, $fit->keptTurns);
        self::assertSame(1, $fit->droppedTurns);

        $contents = array_column($fit->messages, 'content');
        self::assertNotContains(str_repeat('a', 700), $contents, 'the oldest turn is shed first');
        self::assertContains(str_repeat('b', 700), $contents);
        self::assertContains(str_repeat('c', 700), $contents, 'the newest turn always survives when it fits');
    }

    /**
     * THE QUESTION RIDES, EVEN WHEN IT IS NOT THE NEWEST TURN.
     *
     * Once a tool loop is under way the newest turns are tool rounds and the
     * question sits behind them — and the endpoint refuses a request that
     * carries no user message at all ("No user query found in messages"). So
     * the question is reserved before any shedding: this budget fits the
     * head, the newest round, and the question, but not the older round, and
     * the shed must take the older round — never the question.
     */
    public function testTheQuestionIsNeverShedEvenWhenToolRoundsAreNewer(): void
    {
        // 600 tokens ≈ 2100 chars: head ~1 token; the question ~200 tokens
        // (~700 chars); each round ~201 tokens (a ~700-char result plus the
        // call arguments). Two rounds cannot share the budget with the
        // question; the question is what stays.
        $window = new ChatContextWindow(contextLimitTokens: 600, windowTurns: 100);

        $fit = $window->fit(new ChatWireWindow([
            $this->turn(str_repeat('q', 700)),
            ...$this->round('old', str_repeat('x', 700)),
            ...$this->round('new', str_repeat('y', 700)),
        ]), 'SYS');

        self::assertTrue($fit->trimmed());
        self::assertSame(1, $fit->keptTurns, 'the question survives');
        self::assertSame(1, $fit->keptRounds, 'the newest round rides beside it');
        self::assertSame(1, $fit->droppedRounds, 'the older round is the casualty');

        $roles = array_column($fit->messages, 'role');
        self::assertContains('user', $roles, 'a request must carry a user message — the endpoint refuses one that does not');

        $contents = array_column($fit->messages, 'content');
        self::assertContains(str_repeat('q', 700), $contents, 'the question is never shed');
        self::assertContains(str_repeat('y', 700), $contents);
        self::assertNotContains(str_repeat('x', 700), $contents, 'the oldest round leaves whole, and first');
    }

    /**
     * A tool round travels whole or not at all: when the budget forces it out,
     * neither its `tool_calls` message nor any of its results remain.
     */
    public function testAToolRoundIsShedWhole(): void
    {
        // Big enough for the head + the newest plain turn + the newest round,
        // not for the oldest round.
        $window = new ChatContextWindow(contextLimitTokens: 700, windowTurns: 100);

        $oldRound = [
            $this->toolCall('old', 'old-b'),
            $this->toolResult('old', str_repeat('x', 300)),
            $this->toolResult('old-b', str_repeat('y', 300)),
        ];

        $fit = $window->fit(new ChatWireWindow([
            ...$oldRound,
            $this->turn('and now?'),
        ]), 'SYS');

        // Whatever the budget did, no orphan survives: every `tool` message's
        // call is announced earlier in the same list, and no `tool_calls`
        // message is left without its results.
        $announced = [];
        $results = [];
        foreach ($fit->messages as $message) {
            foreach (($message['tool_calls'] ?? []) as $call) {
                $announced[(string) $call['id']] = true;
            }

            if ('tool' === $message['role']) {
                $id = (string) ($message['tool_call_id'] ?? '');
                $results[$id] = true;
                self::assertArrayHasKey($id, $announced, 'a tool result without its announcing call is a malformed request');
            }
        }

        foreach (array_keys($announced) as $id) {
            self::assertArrayHasKey($id, $results, 'a tool call whose results were cut away is a malformed request');
        }
    }

    /**
     * The fail-closed floor, in the run engine's own words: a head that
     * cannot fit is a named exhaustion, not a truncated prompt.
     */
    public function testAHeadThatCannotFitFailsClosed(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 10, windowTurns: 100);

        $this->expectException(ContextExhaustedException::class);
        $this->expectExceptionMessage('context exhausted');

        $window->fit(new ChatWireWindow([$this->turn('hi')]), str_repeat('S', 500));
    }

    /**
     * And the second floor: the head may fit, but if the newest turn — the
     * question being answered — does not, the request fails rather than
     * sending a conversation with no question in it.
     */
    public function testAnUnfittableNewestTurnFailsClosed(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 100, windowTurns: 100);

        $this->expectException(ContextExhaustedException::class);
        $this->expectExceptionMessage('the newest turn');

        $window->fit(new ChatWireWindow([$this->turn(str_repeat('x', 10_000))]), 'SYS');
    }

    /**
     * And the third floor: the newest turn and the question may each fit
     * alone, but if they cannot fit TOGETHER the request must fail closed —
     * keeping the newest turn by shedding the question is exactly the request
     * the endpoint refuses, so it is never produced.
     */
    public function testANewestTurnAndQuestionThatCannotFitTogetherFailClosed(): void
    {
        // The newest round alone fits (head ~1 + ~201 tokens); the question
        // (~429 tokens) pushes the pair over the 600-token limit.
        $window = new ChatContextWindow(contextLimitTokens: 600, windowTurns: 100);

        $this->expectException(ContextExhaustedException::class);
        $this->expectExceptionMessage('question');

        $window->fit(new ChatWireWindow([
            $this->turn(str_repeat('q', 1500)),
            ...$this->round('new', str_repeat('y', 700)),
        ]), 'SYS');
    }

    /**
     * And the floor beneath the floors: a window carrying no user-side turn
     * at all (a caller that handed the fit tool rounds alone) is refused —
     * there is no request it could legally become.
     */
    public function testAWindowWithNoQuestionAtAllIsRefused(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 100_000, windowTurns: 100);

        $this->expectException(ContextExhaustedException::class);
        $this->expectExceptionMessage('no question');

        $window->fit(new ChatWireWindow([...$this->round('only', 'x')]), 'SYS');
    }

    /**
     * A read bound that bites counts into the trim: the turns the read
     * already left behind are added to the fit's own drops, by kind.
     */
    public function testTurnsTheReadLeftBehindAreCountedIntoTheTrim(): void
    {
        $window = new ChatContextWindow(contextLimitTokens: 100_000, windowTurns: 100);

        $fit = $window->fit(new ChatWireWindow([$this->turn('only one')], olderTurns: 12, olderRounds: 3), 'SYS');

        self::assertTrue($fit->trimmed());
        self::assertSame(12, $fit->droppedTurns);
        self::assertSame(3, $fit->droppedRounds);
        self::assertSame(1, $fit->keptTurns);
    }

    private function turn(string $content): ChatExchangeEvent
    {
        return ChatExchangeEvent::turn(
            ChatEventType::Message,
            Participant::Andrew,
            TurnRole::User,
            ChatOrigin::Web,
            $content,
        );
    }

    private function toolCall(string ...$ids): ChatExchangeEvent
    {
        $event = new ChatExchangeEvent(ChatEventType::ToolCall);
        $event->setPayload([
            'calls' => array_map(
                static fn (string $id): array => ['id' => $id, 'name' => 'get_weather', 'arguments' => []],
                $ids,
            ),
            'assistantContent' => null,
        ]);

        return $event;
    }

    private function toolResult(string $id, string $content): ChatExchangeEvent
    {
        $event = new ChatExchangeEvent(ChatEventType::ToolResult);
        $event->setPayload(['tool' => 'get_weather', 'content' => $content, 'isError' => false, 'toolCallId' => $id]);

        return $event;
    }

    /** @return list<ChatExchangeEvent> a whole tool round: the call and one result */
    private function round(string $id, string $result): array
    {
        return [$this->toolCall('call-'.$id), $this->toolResult('call-'.$id, $result)];
    }
}
