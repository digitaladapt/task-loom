<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Entity\ErrorClass;
use App\Entity\Participant;
use App\Entity\TurnRole;
use PHPUnit\Framework\TestCase;

/**
 * The chat exchange's own state machine (SPEC §15) — the aggregate's shape,
 * its four states, and what it refuses to be.
 */
final class ChatExchangeTest extends TestCase
{
    public function testAFreshExchangeIsQueuedAndOwesAReply(): void
    {
        $exchange = new ChatExchange(new Chat('Hello'));

        self::assertSame(ChatExchangeStatus::Queued, $exchange->getStatus());
        self::assertFalse($exchange->isTerminal());
        self::assertNull($exchange->getStartedAt());
        self::assertNull($exchange->getFinishedAt());
    }

    public function testAConversationIsNotARunAndItsStatesAreItsOwn(): void
    {
        // The design's §3.3 argument, asserted: sharing RunStatus would drag
        // `paused`/`needs_attention`/`incomplete` into a domain with no use for
        // them. Four states, and none of the run's completion semantics — a
        // reply is not a "justified completion".
        self::assertSame(
            ['queued', 'running', 'answered', 'failed'],
            array_map(static fn (ChatExchangeStatus $s): string => $s->value, ChatExchangeStatus::cases()),
        );
    }

    public function testAnsweringSettlesTheExchangeWithoutClaimingTheConversationIsOver(): void
    {
        $exchange = new ChatExchange(new Chat('Hello'));

        $exchange->markStarted();
        self::assertSame(ChatExchangeStatus::Running, $exchange->getStatus());
        self::assertFalse($exchange->isTerminal());

        $exchange->markAnswered();
        self::assertSame(ChatExchangeStatus::Answered, $exchange->getStatus());
        self::assertTrue($exchange->isTerminal());
        self::assertNotNull($exchange->getStartedAt());
        self::assertNotNull($exchange->getFinishedAt());
    }

    public function testAFailedExchangeCarriesItsClassifiedReason(): void
    {
        $exchange = new ChatExchange(new Chat('Hello'));

        $exchange->markFailed(ErrorClass::LlmError);

        self::assertSame(ChatExchangeStatus::Failed, $exchange->getStatus());
        self::assertSame(ErrorClass::LlmError, $exchange->getErrorClass());
        self::assertTrue($exchange->isTerminal());
    }

    public function testEventsAreNumberedWithinTheExchangeNotTheConversation(): void
    {
        $exchange = new ChatExchange(new Chat('Hello'));

        $first = $exchange->appendEvent($this->turn('one'));
        $second = $exchange->appendMachinery(ChatEventType::Checkpoint, ['status' => 'running']);
        $third = $exchange->appendEvent($this->turn('two'));

        self::assertSame(1, $first->getSeq());
        self::assertSame(2, $second->getSeq());
        self::assertSame(3, $third->getSeq());
    }

    public function testAConversationNeedsATitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Chat('   ');
    }

    public function testTouchingAConversationMovesItsActivityTimestamp(): void
    {
        $chat = new Chat('Hello');
        $before = $chat->getUpdatedAt();

        // A conversation is listed by "where was I?", so the timestamp has to
        // move when something is said rather than when the row was written.
        usleep(1000);
        $chat->touch();

        self::assertGreaterThan($before, $chat->getUpdatedAt());
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
}
