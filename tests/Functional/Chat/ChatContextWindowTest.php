<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Chat\ChatContextWindow;
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
use App\Llm\LlmResponse;
use App\Repository\ChatExchangeEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The chat context window, end to end (SPEC §15.9).
 *
 * A conversation does not end, so it eventually outgrows the model's window;
 * this is the policy that keeps it sendable and — the load-bearing half —
 * keeps the shedding *visible*. The LLM is the only thing faked; the read,
 * the ledger, the fit and the trim record are all real.
 *
 * The tests that need a smaller window replace the real `ChatContextWindow`
 * service with the same production class and a smaller budget, then ask the
 * container for the engine — so the fit is decided by production semantics,
 * not by a mock of them.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatContextWindowTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        foreach (['ChatExchangeEvent', 'ChatExchange', 'Chat'] as $entity) {
            $this->em->createQuery('DELETE FROM App\\Entity\\'.$entity)->execute();
        }
        $this->em->flush();

        $this->llm = $this->createMock(LlmClientInterface::class);
        static::getContainer()->set(LlmClientInterface::class, $this->llm);
    }

    /**
     * A LONG CONVERSATION IS FIT, NOT SENT UNBOUNDED — and what was shed is
     * recorded, with the counts the surface reads back.
     */
    public function testAnOvergrownConversationIsTrimmedAndTheTrimIsRecorded(): void
    {
        $engine = $this->engineWithWindow(600); // tokens: a few bulky turns, not eight

        $chat = $this->seedAnsweredConversation(turns: 8, bodyChars: 400);
        $exchange = $engine->ask($chat, str_repeat('q', 200), ChatOrigin::Web);

        $sent = null;
        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$sent): LlmResponse {
                $sent = $messages;

                return new LlmResponse('ok', 'stop', [], [], null, 5);
            });

        $engine->reply((int) $exchange->getId());

        self::assertIsArray($sent, 'the model was asked');

        // Untrimmed this conversation is 16 seeded turns + the new ask + the
        // system message; strictly fewer means the window did its job.
        self::assertLessThan(18, \count($sent), 'an overgrown conversation must not be sent whole');
        self::assertStringNotContainsString('question 1 ', (string) json_encode($sent), 'the oldest turns leave the window first');

        $trim = $this->latestTrim($exchange);
        self::assertNotNull($trim, 'the shedding must be recorded — a silent trim is the failure this exists to prevent');
        self::assertSame(ChatEventType::ContextTrim, $trim->getType());
        self::assertSame(600, $trim->getPayload()['limitTokens'] ?? null);
        self::assertGreaterThan(0, $trim->getPayload()['droppedTurns'] ?? 0);
        self::assertGreaterThan(0, $trim->getPayload()['keptTurns'] ?? 0);
        self::assertLessThanOrEqual(600, (int) ($trim->getPayload()['estimatedTokens'] ?? 0));
    }

    /**
     * The newest turn is never optional — it is the question being answered.
     * When even head + newest turn cannot fit, the exchange fails *closed*
     * with a named reason, rather than flying at the provider to return a raw
     * transport error in front of a person.
     */
    public function testAnUnfittableTurnFailsClosedWithANamedReason(): void
    {
        $engine = $this->engineWithWindow(50);

        $chat = $this->newChat('Unfittable');
        $exchange = $engine->ask($chat, str_repeat('x', 10_000), ChatOrigin::Web);

        $this->llm->expects(self::never())->method('chat');

        $status = $engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Failed, $status);

        $failure = $this->failureOf($exchange);
        self::assertNotNull($failure);
        self::assertSame(ErrorClass::ContextExhausted, $failure->getErrorClass());
        self::assertStringContainsString('context exhausted', (string) ($failure->getPayload()['reason'] ?? ''));
    }

    /**
     * THE WHOLE-UNIT DROP, as the endpoint sees it: when the window sheds tool
     * rounds, every `tool` message that remains is still preceded by the
     * assistant `tool_calls` message that announced it. Half a round is a
     * malformed request, and this is the property the shed unit exists to
     * keep.
     */
    public function testTheWindowNeverSendsHalfAToolRound(): void
    {
        $engine = $this->engineWithWindow(700);

        $chat = $this->newChat('Tool rounds');
        for ($round = 1; $round <= 6; ++$round) {
            $this->seedToolRound($chat, $round, bodyChars: 220);
        }

        $exchange = $engine->ask($chat, 'last question', ChatOrigin::Web);

        $sent = null;
        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$sent): LlmResponse {
                $sent = $messages;

                return new LlmResponse('done', 'stop', [], [], null, 5);
            });

        $engine->reply((int) $exchange->getId());

        self::assertIsArray($sent);

        $announced = [];
        $toolMessages = 0;
        foreach ($sent as $message) {
            foreach (($message['tool_calls'] ?? []) as $call) {
                $announced[(string) ($call['id'] ?? '')] = true;
            }

            if ('tool' === ($message['role'] ?? null)) {
                ++$toolMessages;
                self::assertArrayHasKey(
                    (string) ($message['tool_call_id'] ?? ''),
                    $announced,
                    'a tool message must never be sent without its announcing tool_calls message',
                );
            }
        }

        self::assertGreaterThan(0, $toolMessages, 'the retained window still carries tool rounds');
        self::assertLessThan(12, $toolMessages, 'and some rounds were shed — otherwise this proves nothing');

        $trim = $this->latestTrim($exchange);
        self::assertNotNull($trim);
        self::assertGreaterThan(0, $trim->getPayload()['droppedRounds'] ?? 0, 'the shed rounds are counted, by kind');
    }

    /**
     * The read bound is chat's own knob: with a small `windowTurns`, the
     * oldest turns are out of the read entirely, and the trim says so — the
     * gap between what the read carried and what it left behind is counted,
     * never silently dropped.
     */
    public function testTheTurnBoundIsCountedIntoTheTrim(): void
    {
        $engine = $this->engineWithWindow(100_000, turns: 3); // budget huge: only the read bound can bite

        $chat = $this->seedAnsweredConversation(turns: 10, bodyChars: 4);
        $exchange = $engine->ask($chat, 'and?', ChatOrigin::Web);

        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturn(new LlmResponse('fine', 'stop', [], [], null, 5));

        $engine->reply((int) $exchange->getId());

        $trim = $this->latestTrim($exchange);
        self::assertNotNull($trim, 'turns beyond the read bound were not counted — that is the silent loss');
        self::assertSame(3, $trim->getPayload()['keptTurns'] ?? null, 'exactly the read bound remained');
        self::assertGreaterThanOrEqual(10, $trim->getPayload()['droppedTurns'] ?? 0);
    }

    /**
     * The common case gains no ledger row: a conversation well inside the
     * window is not announced as trimmed, so a reader can tell "nothing was
     * trimmed" from "trimming was not recorded".
     */
    public function testAShortConversationIsNotAnnouncedAsTrimmed(): void
    {
        $engine = $this->engineWithWindow(32_768);

        $chat = $this->seedAnsweredConversation(turns: 2, bodyChars: 10);
        $exchange = $engine->ask($chat, 'still short', ChatOrigin::Web);

        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturn(new LlmResponse('yes', 'stop', [], [], null, 5));

        $engine->reply((int) $exchange->getId());

        self::assertNull($this->latestTrim($exchange), 'a conversation inside the window must not record a trim');
        self::assertSame(ChatExchangeStatus::Answered, $this->refresh($exchange)->getStatus());
    }

    /**
     * Swap the real window service for the same class with a smaller budget,
     * then build the engine — so the engine constructed in this test (a fresh
     * kernel and container per test) resolves the swapped window.
     */
    private function engineWithWindow(int $tokens, int $turns = 100): ChatEngine
    {
        static::getContainer()->set(
            ChatContextWindow::class,
            new ChatContextWindow(contextLimitTokens: $tokens, maxToolOutputPct: 15.0, windowTurns: $turns),
        );

        return static::getContainer()->get(ChatEngine::class);
    }

    /** A conversation of N answered exchanges, each with a bulky reply. */
    private function seedAnsweredConversation(int $turns, int $bodyChars): Chat
    {
        $chat = $this->newChat('Long conversation');

        for ($i = 1; $i <= $turns; ++$i) {
            $exchange = $this->openExchange($chat);

            $exchange->appendEvent(ChatExchangeEvent::turn(
                ChatEventType::Message,
                Participant::Andrew,
                TurnRole::User,
                ChatOrigin::Web,
                "question $i ".str_repeat('q', $bodyChars),
            ));
            $exchange->appendEvent(ChatExchangeEvent::turn(
                ChatEventType::Reply,
                Participant::Nia,
                TurnRole::Assistant,
                ChatOrigin::Web,
                "answer $i ".str_repeat('a', $bodyChars),
            ));

            $this->settle($exchange);
        }

        $this->em->flush();

        return $chat;
    }

    /** One answered exchange holding a whole wire tool round. */
    private function seedToolRound(Chat $chat, int $round, int $bodyChars): void
    {
        $exchange = $this->openExchange($chat);

        $exchange->appendEvent(ChatExchangeEvent::turn(
            ChatEventType::Message,
            Participant::Andrew,
            TurnRole::User,
            ChatOrigin::Web,
            "weather? ($round)",
        ));

        $exchange->appendMachinery(ChatEventType::ToolCall, [
            'calls' => [
                ['id' => "call-$round", 'name' => 'get_weather', 'arguments' => []],
                ['id' => "call-$round-b", 'name' => 'get_weather', 'arguments' => []],
            ],
            'assistantContent' => null,
        ]);
        // Two results for the two announced calls, so the round has more than
        // one `tool` message — a cut between them would be as malformed as a
        // cut before the first.
        foreach (["call-$round", "call-$round-b"] as $index => $callId) {
            $exchange->appendMachinery(ChatEventType::ToolResult, [
                'tool' => 'get_weather',
                'content' => str_repeat((string) $index, $bodyChars),
                'isError' => false,
                'toolCallId' => $callId,
            ]);
        }

        $exchange->appendEvent(ChatExchangeEvent::turn(
            ChatEventType::Reply,
            Participant::Nia,
            TurnRole::Assistant,
            ChatOrigin::Web,
            "It is mild. ($round)",
        ));

        $this->settle($exchange);
    }

    private function openExchange(Chat $chat): ChatExchange
    {
        $exchange = new ChatExchange($chat);
        $chat->appendExchange($exchange);
        $this->em->persist($exchange);

        return $exchange;
    }

    private function settle(ChatExchange $exchange): void
    {
        $exchange->markStarted();
        $exchange->markAnswered();
        $this->em->flush();
    }

    private function newChat(string $title): Chat
    {
        $chat = new Chat($title);
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function refresh(ChatExchange $exchange): ChatExchange
    {
        $this->em->refresh($exchange);

        return $exchange;
    }

    private function latestTrim(ChatExchange $exchange): ?ChatExchangeEvent
    {
        foreach (static::getContainer()->get(ChatExchangeEventRepository::class)->findForExchange($exchange) as $event) {
            if (ChatEventType::ContextTrim === $event->getType()) {
                return $event;
            }
        }

        return null;
    }

    private function failureOf(ChatExchange $exchange): ?ChatExchangeEvent
    {
        foreach (static::getContainer()->get(ChatExchangeEventRepository::class)->findForExchange($exchange) as $event) {
            if (ChatEventType::Failure === $event->getType()) {
                return $event;
            }
        }

        return null;
    }
}
