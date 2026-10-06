<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Chat\ChatEngine;
use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Entity\ErrorClass;
use App\Entity\Participant;
use App\Entity\TurnRole;
use App\Llm\LlmClientInterface;
use App\Llm\LlmRequestException;
use App\Llm\LlmResponse;
use App\Message\ChatReplyMessage;
use App\Repository\ChatExchangeEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The chat loop, end to end against the real container (SPEC §15).
 *
 * The LLM is a stub — that is the only thing faked. Everything else is real:
 * the claim protocol against the real table, the transcript read, the ledger,
 * the lane dispatch.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatEngineTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ChatEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchangeEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchange')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Chat')->execute();
        $this->em->flush();

        $this->llm = $this->createMock(LlmClientInterface::class);

        $container = static::getContainer();
        $container->set(LlmClientInterface::class, $this->llm);
        $this->engine = $container->get(ChatEngine::class);
    }

    /**
     * Saying something records the turn with attribution and starts exactly one
     * exchange, with its reply carrier committed alongside it.
     */
    public function testAskingRecordsTheTurnAndDispatchesTheReply(): void
    {
        $chat = $this->newChat();

        $exchange = $this->engine->ask($chat, '  what time is it?  ', ChatOrigin::Web);

        $this->em->refresh($exchange);

        self::assertSame(ChatExchangeStatus::Queued, $exchange->getStatus());

        $transcript = $this->transcript($chat);
        self::assertCount(1, $transcript);

        $turn = $transcript[0];
        self::assertSame(ChatEventType::Message, $turn->getType());
        self::assertSame(Participant::Andrew, $turn->getSpeaker(), 'attribution is applied by the pipeline, never typed by the human');
        self::assertSame(TurnRole::User, $turn->getRole());
        self::assertSame(ChatOrigin::Web, $turn->getOrigin());
        self::assertSame('what time is it?', $turn->getContent(), 'the message is stored trimmed');

        self::assertCount(1, $this->sent(), 'the reply carrier is committed inside the same transaction as the turn');
    }

    /**
     * The reply lands as a turn *from the assistant*, on the assistant side,
     * and the exchange settles as answered — not as a run-style completion.
     */
    public function testReplyingRecordsTheAssistantsTurnWithItsOwnAttribution(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'morning');

        $this->expectLlmReply('Morning — it is 06:50.');

        $status = $this->engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Answered, $status);

        $transcript = $this->transcript($chat);
        self::assertCount(2, $transcript);

        $reply = $transcript[1];
        self::assertSame(ChatEventType::Reply, $reply->getType());
        self::assertSame(Participant::Nia, $reply->getSpeaker());
        self::assertSame(TurnRole::Assistant, $reply->getRole());
        self::assertSame('Morning — it is 06:50.', $reply->getContent());
    }

    /**
     * THE ATTRIBUTION INVARIANT, as the model actually receives it.
     *
     * This is the test the design's §2.1 asks for: both speakers reach the wire
     * on their own role, and the system message states the mapping. A flattened
     * transcript passes every other test in this file and fails this one.
     */
    public function testTheConversationReachesTheModelAttributedOnBothSides(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'I think the fence needs doing before the rain.');

        $sent = [];

        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $tools = []) use (&$sent): LlmResponse {
                $sent = $messages;

                return new LlmResponse('Agreed.', 'stop', [], [], null, 5);
            });

        $this->engine->reply((int) $exchange->getId());

        // The system message carries the harness voice: the roster and the
        // grounding block, in that order.
        self::assertSame('system', $sent[0]['role']);
        self::assertStringContainsString('Nia', $sent[0]['content']);
        self::assertStringContainsString('Andrew', $sent[0]['content']);
        self::assertStringContainsString('## Grounding', $sent[0]['content']);

        // And the human's words reach the wire on the user side, never the
        // assistant's — the direction that would otherwise turn her own past
        // output into instructions she now follows. (Her own reply is appended
        // after the request returns, so it is not in this request; the next
        // test covers it arriving on the assistant side.)
        $turns = \array_slice($sent, 1);
        self::assertSame(['user'], array_column($turns, 'role'));
        self::assertSame(['I think the fence needs doing before the rain.'], array_column($turns, 'content'));
    }

    /**
     * Re-sending a delivery for an exchange already answered does nothing —
     * and adding nothing is the *correct* processing, not a failure.
     */
    public function testASecondDeliveryForAnAnsweredExchangeIsDropped(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'hello');
        $this->expectLlmReply('Hi.');
        $this->engine->reply((int) $exchange->getId());

        $this->llm->expects(self::never())->method('chat');

        $status = $this->engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Answered, $status);
        self::assertCount(2, $this->transcript($chat), 'the duplicate delivery added nothing to the conversation');
    }

    /**
     * A model failure is terminal, classified, and *recorded*.
     *
     * The failure mode this exists for has no precedent in the run lanes: a run
     * that throws is visible in the run history, and a chat turn that throws is
     * a person watching an empty space. So the exchange has to carry the
     * reason, which is what the surface renders.
     */
    public function testAModelFailureIsRecordedAndVisibleRatherThanRetried(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'hello');

        $this->llm->expects(self::once())
            ->method('chat')
            ->willThrowException(LlmRequestException::transport(new \RuntimeException('endpoint unreachable')));

        $status = $this->engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Failed, $status);

        $failure = $this->failureEvent($exchange);
        self::assertNotNull($failure);
        self::assertSame(ErrorClass::LlmError, $failure->getErrorClass());
        self::assertStringContainsString('endpoint unreachable', (string) ($failure->getPayload()['reason'] ?? ''));

        // Deliberately NOT re-dispatched: a model that is down will still be
        // down a second later, and a hot retry loop against a single-slot
        // server is how one conversation stops every task. The human's next
        // message is the retry. Exactly one dispatch, the one the ask made.
        self::assertCount(1, $this->sent());
    }

    /**
     * An empty reply is a failure, not a blank bubble the human reads as
     * silence — the same judgement the run engine makes about a contentless
     * terminal message (SPEC §5.4).
     */
    public function testAnEmptyReplyIsAFailureNotSilence(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'hello');

        $this->expectLlmReply('   ');

        $status = $this->engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Failed, $status);
        self::assertSame(ErrorClass::LlmMalformedResponse, $this->failureEvent($exchange)?->getErrorClass());
        self::assertCount(1, $this->transcript($chat), 'nothing was added to the conversation');
    }

    /**
     * The claim is released on every exit, so the next delivery can take the
     * exchange — including after a failure, which is what makes a human's
     * "try again" possible at all.
     */
    public function testTheClaimIsReleasedSoTheNextDeliveryCanTakeTheExchange(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'hello');
        $this->expectLlmReply('Hi.');
        $this->engine->reply((int) $exchange->getId());

        $this->em->refresh($exchange);

        self::assertNull($exchange->getClaimedAt(), 'the claim clears when the turn ends');
        self::assertNull($exchange->getClaimFleet());
    }

    /**
     * Recovery: an exchange whose carrier was lost is re-derivable from
     * committed state alone — the property `app:chat:requeue` depends on.
     */
    public function testAnActiveExchangeOwesAReplyAndAnAnsweredOneOwesNothing(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'hello');

        $message = $this->engine->nextTurnMessage($exchange);
        self::assertInstanceOf(ChatReplyMessage::class, $message);
        self::assertSame((int) $exchange->getId(), $message->exchangeId);
        self::assertSame((int) $chat->getId(), $message->chatId);

        $this->expectLlmReply('Hi.');
        $this->engine->reply((int) $exchange->getId());
        $this->em->refresh($exchange);

        self::assertNull($this->engine->nextTurnMessage($exchange), 'an answered exchange owes nothing');
    }

    /**
     * The transcript is a filtered read over the ledger, so the machinery rows
     * are present in the exchange but absent from the conversation. If this
     * ever regresses, the model starts seeing its own request bookkeeping as
     * things somebody said.
     */
    public function testTheTranscriptExcludesTheMachineryRows(): void
    {
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'hello');
        $this->expectLlmReply('Hi.');
        $this->engine->reply((int) $exchange->getId());

        $all = static::getContainer()->get(ChatExchangeEventRepository::class)->findForExchange($exchange);

        $types = array_map(static fn (ChatExchangeEvent $e): string => $e->getType()->value, $all);
        self::assertContains('llm_request', $types, 'the machinery is in the ledger');
        self::assertContains('checkpoint', $types);

        self::assertCount(2, $this->transcript($chat), 'and only the two turns are in the conversation');
    }

    /**
     * The transcript read keeps the turns of a *later* exchange in order
     * alongside the earlier ones.
     *
     * `seq` restarts at 1 in each exchange and is a position within an
     * execution, not within the conversation — so an ordering bug here is
     * invisible until two exchanges exist, at which point it interleaves the
     * conversation backwards.
     */
    public function testTheTranscriptOrdersAcrossExchanges(): void
    {
        $chat = $this->newChat();

        // Two exchanges, two replies — a response queue rather than two
        // `expects(once())` calls, which PHPUnit counts in total across the
        // test rather than per call site.
        $replies = ['reply one', 'reply two'];
        $this->llm->expects(self::exactly(2))
            ->method('chat')
            // A closure, not an arrow fn: an arrow fn captures by value, so the
            // queue would never advance and both replies would be the first.
            ->willReturnCallback(function () use (&$replies): LlmResponse {
                return new LlmResponse((string) array_shift($replies), 'stop', [], [], null, 7);
            });

        $first = $this->engine->ask($chat, 'first');
        $this->engine->reply((int) $first->getId());

        $second = $this->engine->ask($chat, 'second');
        $this->engine->reply((int) $second->getId());

        self::assertSame(
            ['first', 'reply one', 'second', 'reply two'],
            array_map(static fn (ChatExchangeEvent $e): string => (string) $e->getContent(), $this->transcript($chat)),
        );

        self::assertSame(
            ['user', 'assistant', 'user', 'assistant'],
            array_map(static fn (ChatExchangeEvent $e): string => (string) $e->getRole()?->value, $this->transcript($chat)),
        );
    }

    /**
     * A conversation's title comes from its opening words, so nothing has to be
     * named before it can be spoken in.
     */
    public function testStartingAConversationNamesItFromItsFirstMessage(): void
    {
        $chat = $this->engine->start("What's the weather look like?\nAnd the fence?", ChatOrigin::Web);

        self::assertSame("What's the weather look like? And the fence?", $chat->getTitle());
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $chat = $this->newChat();

        $this->expectException(\InvalidArgumentException::class);

        $this->engine->ask($chat, "  \n  ");
    }

    private function newChat(): Chat
    {
        $chat = new Chat('Test conversation');
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function expectLlmReply(string $content): void
    {
        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturn(new LlmResponse($content, 'stop', [], ['total_tokens' => 12], null, 42));
    }

    /** @return list<ChatExchangeEvent> */
    private function transcript(Chat $chat): array
    {
        return static::getContainer()->get(ChatExchangeEventRepository::class)->findTranscript($chat);
    }

    private function failureEvent(ChatExchange $exchange): ?ChatExchangeEvent
    {
        foreach (static::getContainer()->get(ChatExchangeEventRepository::class)->findForExchange($exchange) as $event) {
            if (ChatEventType::Failure === $event->getType()) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Everything dispatched to the chat lane so far.
     *
     * The lane is never consumed here: these tests drive the engine directly,
     * so the carrier is asserted where it *is* the point (the ask, committed
     * with the turn) and its absence asserted where that is the point (a
     * failure that must not become a hot retry loop).
     *
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function sent(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.chat');

        return $transport->getSent();
    }
}
