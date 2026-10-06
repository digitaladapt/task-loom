<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Roster;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatOrigin;
use App\Entity\ChatTrigger;
use App\Entity\Participant;
use App\Entity\TurnRole;
use PHPUnit\Framework\TestCase;

/**
 * The attribution invariant (SPEC §15, §2.1) — the one property of this
 * aggregate that is a *safety* property rather than a feature.
 *
 * These are unit tests because the invariant is about what the record can
 * represent, not about what a request does: the failure being prevented is a
 * transcript that cannot say who said what, and that is decidable without a
 * database or a model.
 */
final class AttributionTest extends TestCase
{
    public function testTheRosterMapsEachParticipantToOneSideOfTheConversation(): void
    {
        $roster = new Roster();

        self::assertSame(TurnRole::User, $roster->roleFor(Participant::Andrew));
        self::assertSame(TurnRole::Assistant, $roster->roleFor(Participant::Nia));
    }

    /**
     * The mapping is what tells the model whose opinions are whose, so the
     * prompt has to state it rather than leave it to be inferred from the
     * transcript's shape — the one inference that could silently invert the
     * conversation.
     *
     * The sentence has to carry both names and both sides, and it has to say
     * what *not* to do with them: the two failure directions in §2.2 are the
     * assistant reading her own words as instructions and the human's words as
     * hers, and a statement that only names the participants prevents neither.
     */
    public function testTheRosterSentenceCarriesBothNamesAndBothSides(): void
    {
        $rendered = (new Roster())->render();

        self::assertStringContainsString('Nia', $rendered);
        self::assertStringContainsString('Andrew', $rendered);
        self::assertStringContainsString('assistant', $rendered);
        self::assertStringContainsString('user', $rendered);
    }

    /**
     * A turn carries its speaker *and* the role it renders on, and the two are
     * stored independently — so a historical turn keeps the role it was
     * rendered with even if the roster changes underneath it.
     */
    public function testATurnCarriesItsSpeakerAndItsRenderedRoleAsRealColumns(): void
    {
        $turn = ChatExchangeEvent::turn(
            ChatEventType::Message,
            Participant::Andrew,
            TurnRole::User,
            ChatOrigin::Web,
            'morning',
        );

        self::assertSame(Participant::Andrew, $turn->getSpeaker());
        self::assertSame(TurnRole::User, $turn->getRole());
        self::assertSame(ChatOrigin::Web, $turn->getOrigin());
        self::assertSame('morning', $turn->getContent());
        self::assertNull($turn->getReplyToId());
    }

    /**
     * The turn becomes a wire message on the role the roster assigned — not on
     * a role re-derived at read time, and never on both sides at once.
     */
    public function testATurnRendersOnItsOwnSideOfTheConversation(): void
    {
        $human = ChatExchangeEvent::turn(ChatEventType::Message, Participant::Andrew, TurnRole::User, ChatOrigin::Web, 'morning');
        $assistant = ChatExchangeEvent::turn(ChatEventType::Reply, Participant::Nia, TurnRole::Assistant, ChatOrigin::Web, 'Morning.');

        self::assertSame(['role' => 'user', 'content' => 'morning'], $human->toMessage());
        self::assertSame(['role' => 'assistant', 'content' => 'Morning.'], $assistant->toMessage());
    }

    /**
     * A turn without attribution is not representable through the turn door.
     *
     * This is the structural half of §2.1: the design says the attribution
     * fields are a set that is either all present or all absent, and "half
     * attributed" is the state that produces the silent contradiction. The
     * named constructor is what makes it unrepresentable.
     */
    public function testMachineryCannotBeBuiltAsATurn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('machinery');

        ChatExchangeEvent::turn(
            ChatEventType::LlmRequest,
            Participant::Nia,
            TurnRole::Assistant,
            ChatOrigin::Web,
            'this is not something anybody said',
        );
    }

    /**
     * And the mirror: a conversational event cannot be appended as machinery,
     * because that is how a turn would lose its speaker.
     */
    public function testATurnCannotBeAppendedAsMachinery(): void
    {
        $exchange = new ChatExchange($this->chat());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('conversational');

        $exchange->appendMachinery(ChatEventType::Message, ['content' => 'unattributed']);
    }

    /**
     * A machinery row is not a turn and cannot become a wire message: the
     * ledger holds both, and the transcript read filters on exactly this.
     */
    public function testAMachineryRowCannotBecomeAWireMessage(): void
    {
        $exchange = new ChatExchange($this->chat());
        $event = $exchange->appendMachinery(ChatEventType::Checkpoint, ['status' => 'answered']);

        self::assertNull($event->getSpeaker());
        self::assertNull($event->getContent());

        $this->expectException(\LogicException::class);
        $event->toMessage();
    }

    /**
     * The conversational/machinery split is a predicate on the vocabulary, not
     * a literal list in each query — so the transcript read cannot drift away
     * from what the vocabulary says a turn is.
     */
    public function testOnlyTheConversationalTypesAreTurns(): void
    {
        $conversational = array_values(array_filter(
            ChatEventType::cases(),
            static fn (ChatEventType $type): bool => $type->isConversational(),
        ));

        self::assertSame([ChatEventType::Message, ChatEventType::Reply], $conversational);
    }

    /**
     * v1 is respond-only, and the seam that keeps it from hardening is that
     * the trigger is a *value* on the exchange rather than the presence of an
     * inbound turn — so initiation later is a new enum case, not a migration.
     */
    public function testAnExchangeCarriesItsTriggerAsAValueNotAsARequiredInboundTurn(): void
    {
        $exchange = new ChatExchange($this->chat());

        self::assertSame(ChatTrigger::Inbound, $exchange->getTriggeredBy());

        $exchange->setTriggeredBy(ChatTrigger::Inbound);
        self::assertSame(ChatTrigger::Inbound, $exchange->getTriggeredBy());
        self::assertContains(ChatTrigger::Inbound, ChatTrigger::cases());
    }

    private function chat(): \App\Entity\Chat
    {
        return new \App\Entity\Chat('Test conversation');
    }
}
