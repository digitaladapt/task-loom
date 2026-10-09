<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Chat\ChatEngine;
use App\Chat\ChatToolbox;
use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Entity\ErrorClass;
use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\ChatReplyMessage;
use App\Message\ChatToolTurnMessage;
use App\Repository\ChatExchangeEventRepository;
use App\RunEngine\ToolboxSnapshot;
use App\RunEngine\ToolExecutionException;
use App\RunEngine\ToolExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Chat with tools (SPEC §15, `docs/design/CHAT_TOOLS.md`).
 *
 * The LLM and the tool executor are stubs — the only two things faked. The
 * claim protocol, the frozen snapshot, the ledger, the lanes and the loop
 * ceiling are all real.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatToolsTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ChatEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        foreach (['ChatExchangeEvent', 'ChatExchange', 'Chat', 'Tool', 'McpServer'] as $entity) {
            $this->em->createQuery('DELETE FROM App\\Entity\\'.$entity)->execute();
        }
        $this->em->flush();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->executor = $this->createMock(ToolExecutorInterface::class);

        $container = static::getContainer();
        $container->set(LlmClientInterface::class, $this->llm);
        $container->set(ToolExecutorInterface::class, $this->executor);
        $this->engine = $container->get(ChatEngine::class);
    }

    /**
     * THE FROZEN TOOLBOX, from the wire's point of view: the tools declared
     * for an exchange are the tools sent on its request.
     */
    public function testTheExchangeSendsTheToolsItWasGiven(): void
    {
        $tool = $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $sent = [];
        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $tools = []) use (&$sent): LlmResponse {
                $sent = $tools;

                return new LlmResponse('Fine.', 'stop', [], [], null, 5);
            });

        $exchange = $this->engine->ask($chat, 'what is the weather?', ChatOrigin::Web, $this->byTag(['weather']));
        $this->engine->reply((int) $exchange->getId());

        self::assertCount(1, $sent);
        self::assertSame('get_weather', $sent[0]['function']['name']);
        self::assertSame(ChatExchangeStatus::Answered, $this->refresh($exchange)->getStatus());
        self::assertSame('get_weather', $this->refresh($exchange)->getToolboxSnapshot()[0]['tool'] ?? null);
    }

    /**
     * A conversation with NO tools sends none — and the prompt says so.
     *
     * The two preambles exist because a prompt that claims "you have no tools"
     * while tool definitions ride the same request is a contradiction the model
     * resolves unpredictably. Both halves are asserted here.
     */
    public function testAnExchangeWithNoToolsSendsNoneAndSaysSo(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $sent = [];
        $system = '';
        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $tools = []) use (&$sent, &$system): LlmResponse {
                $sent = $tools;
                $system = $messages[0]['content'];

                return new LlmResponse('I cannot check that.', 'stop', [], [], null, 5);
            });

        $exchange = $this->engine->ask($chat, 'what is the weather?');
        $this->engine->reply((int) $exchange->getId());

        self::assertSame([], $sent);
        self::assertStringContainsString('You have no tools', $system);
    }

    /**
     * An exchange WITH tools gets the tools preamble instead.
     */
    public function testAnExchangeWithToolsGetsTheToolsPreamble(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $system = '';
        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $tools = []) use (&$system): LlmResponse {
                $system = $messages[0]['content'];

                return new LlmResponse('Fine.', 'stop', [], [], null, 5);
            });

        $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));
        $this->engine->reply((int) $exchange->getId());

        self::assertStringContainsString('You have tools available', $system);
        self::assertStringNotContainsString('You have no tools', $system);
        // The tool posture carries the injection rule explicitly, because in a
        // conversation tool output is one more untrusted thing among many.
        self::assertStringContainsString('never instructions', $system);
    }

    /**
     * THE FULL LOOP: model asks for a tool → the tool runs → the model answers
     * with the result → the exchange settles on one reply.
     */
    public function testAToolRoundTripLandsOneReplyAndAToolInTheLedger(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $exchange = $this->engine->ask($chat, 'what is the weather?', ChatOrigin::Web, $this->byTag(['weather']));

        // First turn: ask for the tool.
        $this->llm->expects(self::exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                new LlmResponse(null, 'tool_calls', [
                    ['id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'Blacksburg']],
                ], [], null, 10),
                new LlmResponse('It is 56°F and clear.', 'stop', [], [], null, 12),
            );

        $this->executor->expects(self::once())
            ->method('validate')
            ->willReturn([]);
        $this->executor->expects(self::once())
            ->method('execute')
            ->with(self::callback(static fn (Tool $t): bool => 'get_weather' === $t->getName()), ['city' => 'Blacksburg'])
            ->willReturn(['content' => '{"temp_f":56,"sky":"clear"}', 'durationMs' => 40]);

        // Turn 1: the LLM turn queues the tool work rather than answering.
        $this->engine->reply((int) $exchange->getId());
        self::assertCount(1, $this->sentTo('tools'), 'the tool turn rides the existing tools lane');
        self::assertInstanceOf(ChatToolTurnMessage::class, $this->sentTo('tools')[0]->getMessage());

        // Turn 2: the tool turn runs it and re-queues a reply turn.
        $this->engine->toolTurn((int) $exchange->getId());
        self::assertCount(2, $this->sentTo('chat'), 'the ask, then the tool turn\'s re-queue, both ride the chat lane');
        self::assertInstanceOf(ChatReplyMessage::class, $this->sentTo('chat')[0]->getMessage());

        // Turn 3: the model's answer with the result in hand.
        $this->engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Answered, $this->refresh($exchange)->getStatus());

        $transcript = $this->transcript($chat);
        self::assertSame(['message', 'tool_call', 'reply'], array_map(
            static fn (ChatExchangeEvent $e): string => $e->getType()->value,
            $transcript,
        ));
        self::assertSame('It is 56°F and clear.', $transcript[2]->getContent());
    }

    /**
     * THE MODEL'S PICTURE OF THE TOOLBOX IS EXACTLY TRUE, after a tool round.
     *
     * The request that follows a tool turn must carry the assistant's
     * `tool_calls` message and the matching `tool` result, in the shape the
     * endpoint requires (a `tool` message whose id has no preceding
     * `tool_calls` is a malformed request). This asserts the *second* request's
     * messages, which is where the attribution invariant and the tool protocol
     * have to hold at once.
     */
    public function testTheRequestAfterAToolRoundCarriesTheCallsAndResults(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));

        $requests = [];
        $this->llm->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$requests): LlmResponse {
                $requests[] = $messages;

                return 1 === \count($requests)
                    ? new LlmResponse(null, 'tool_calls', [
                        ['id' => 'call_1', 'name' => 'get_weather', 'arguments' => []],
                    ], [], null, 10)
                    : new LlmResponse('56°F.', 'stop', [], [], null, 12);
            });

        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['content' => '56', 'durationMs' => 5]);

        $this->engine->reply((int) $exchange->getId());
        $this->engine->toolTurn((int) $exchange->getId());
        $this->engine->reply((int) $exchange->getId());

        $second = $requests[1];
        $roles = array_map(static fn (array $m): string => (string) $m['role'], $second);

        self::assertSame(['system', 'user', 'assistant', 'tool'], $roles);
        self::assertSame('call_1', $second[2]['tool_calls'][0]['id']);
        self::assertSame('get_weather', $second[2]['tool_calls'][0]['function']['name']);
        self::assertSame('call_1', $second[3]['tool_call_id']);
        self::assertSame('56', $second[3]['content']);

        // The human's turn is still attributed on the user side, with the tool
        // plumbing around it — not flattened into one role.
        self::assertSame('weather?', $second[1]['content']);
    }

    /**
     * A tool the model names that is NOT in the frozen toolbox never
     * dispatches — the run engine's injection defence, kept (SPEC §2.1).
     */
    public function testAToolOutsideTheFrozenToolboxNeverDispatches(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $this->seedTool('send_email', ['email']);

        $chat = $this->newChat();
        // Only the weather tool is enabled for this exchange; the model asks
        // for the other one anyway.
        $exchange = $this->engine->ask($chat, 'mail Jessica', ChatOrigin::Web, $this->byTag(['weather']));

        $requests = [];
        $this->llm->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$requests): LlmResponse {
                $requests[] = $messages;

                return 1 === \count($requests)
                    ? new LlmResponse(null, 'tool_calls', [['id' => 'c1', 'name' => 'send_email', 'arguments' => []]], [], null, 5)
                    : new LlmResponse('I cannot send mail here.', 'stop', [], [], null, 5);
            });

        // The executor must never be asked to run anything.
        $this->executor->expects(self::never())->method('execute');
        $this->executor->expects(self::never())->method('validate');

        $this->engine->reply((int) $exchange->getId());
        $this->engine->toolTurn((int) $exchange->getId());
        $this->engine->reply((int) $exchange->getId());

        $refusal = $this->eventOfType($exchange, ChatEventType::ToolError);
        self::assertNotNull($refusal, 'the refusal is recorded');
        self::assertSame(ErrorClass::ToolNotFound, $refusal->getErrorClass());

        // And the model was told, in the structured shape a run uses.
        $roles = array_map(static fn (array $m): string => (string) $m['role'], $requests[1]);
        self::assertSame(['system', 'user', 'assistant', 'tool'], $roles, 'roles were: '.implode(',', $roles));
        $toolMessage = $requests[1][3];
        self::assertSame('tool', $toolMessage['role']);
        self::assertStringContainsString('tool_not_found', (string) $toolMessage['content']);
    }

    /**
     * A FAILING TOOL DOES NOT LOSE THE REPLY.
     *
     * The deliberate difference from a run: the error is recorded, handed to
     * the model, and the conversation continues. A person should not lose
     * their answer because an argument was wrong.
     */
    public function testAToolErrorIsFedBackAndTheConversationContinues(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));

        $requests = [];
        $this->llm->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$requests): LlmResponse {
                $requests[] = $messages;

                return 1 === \count($requests)
                    ? new LlmResponse(null, 'tool_calls', [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]], [], null, 5)
                    : new LlmResponse('The weather service is down — I could not check.', 'stop', [], [], null, 5);
            });

        $this->executor->method('validate')->willReturn([]);
        $this->executor->expects(self::once())
            ->method('execute')
            ->willThrowException(new ToolExecutionException('endpoint unreachable', ErrorClass::ServerError));

        $this->engine->reply((int) $exchange->getId());
        $this->engine->toolTurn((int) $exchange->getId());
        $this->engine->reply((int) $exchange->getId());

        self::assertSame(
            ChatExchangeStatus::Answered,
            $this->refresh($exchange)->getStatus(),
            'a failing tool must not fail the exchange',
        );

        $failed = $this->eventOfType($exchange, ChatEventType::ToolResult);
        self::assertNotNull($failed);
        self::assertSame(ErrorClass::ServerError, $failed->getErrorClass());
        self::assertStringContainsString('tool_error', (string) $requests[1][3]['content']);

        // And the reply still landed.
        $transcript = $this->transcript($chat);
        self::assertSame(ChatEventType::Reply, $transcript[array_key_last($transcript)]->getType());
    }

    /**
     * An unknown argument is fed back as `invalid_arguments`, not dispatched.
     */
    public function testAnInvalidArgumentIsRefusedBeforeDispatch(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));

        $this->llm->method('chat')->willReturn(
            new LlmResponse(null, 'tool_calls', [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['nope' => 1]]], [], null, 5),
        );
        $this->executor->expects(self::once())->method('validate')->willReturn(['missing required "city"']);
        $this->executor->expects(self::never())->method('execute');

        $this->engine->reply((int) $exchange->getId());
        $this->engine->toolTurn((int) $exchange->getId());

        $refusal = $this->eventOfType($exchange, ChatEventType::ToolError);
        self::assertNotNull($refusal);
        self::assertSame(ErrorClass::InvalidArguments, $refusal->getErrorClass());
    }

    /**
     * THE CEILING. A model that keeps asking for tools is stopped, and told
     * why — because chat is the priority head and a long loop is every task
     * waiting behind a person watching a spinner.
     */
    public function testTheToolLoopStopsAtTheCeiling(): void
    {
        putenv('TASKLOOM_CHAT_TOOL_ROUNDS=2');

        try {
            $this->seedTool('get_weather', ['weather']);
            $chat = $this->newChat();
            $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));

            // Always asks for another tool, forever.
            $this->llm->method('chat')->willReturn(
                new LlmResponse(null, 'tool_calls', [['id' => 'c', 'name' => 'get_weather', 'arguments' => []]], [], null, 5),
            );
            $this->executor->method('validate')->willReturn([]);
            $this->executor->method('execute')->willReturn(['content' => '56', 'durationMs' => 1]);

            for ($i = 0; $i < 6; ++$i) {
                $this->engine->reply((int) $exchange->getId());
                $this->engine->toolTurn((int) $exchange->getId());
            }

            $fresh = $this->refresh($exchange);
            self::assertSame(ChatExchangeStatus::Failed, $fresh->getStatus());
            self::assertSame(ErrorClass::BudgetExceeded, $fresh->getErrorClass());

            $failure = $this->eventOfType($fresh, ChatEventType::Failure);
            self::assertNotNull($failure);
            self::assertStringContainsString('tool rounds exhausted', (string) ($failure->getPayload()['reason'] ?? ''));
            self::assertStringContainsString('it is a task', (string) ($failure->getPayload()['reason'] ?? ''));
        } finally {
            putenv('TASKLOOM_CHAT_TOOL_ROUNDS');
        }
    }

    /**
     * Every exchange freezes its OWN toolbox: the authority is the exchange,
     * never a later one (`CHAT_TOOLS.md` §2.1).
     *
     * What the picker *offers* next is a separate question, and a separate
     * test — this one is about the record. Exchange 1's tools must still read
     * as exchange 1's after a second exchange chose differently, because that
     * record is what answers "why could she do that?".
     */
    public function testEachExchangeKeepsItsOwnFrozenToolbox(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $this->llm->method('chat')->willReturn(new LlmResponse('ok', 'stop', [], [], null, 5));

        $first = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));
        $this->engine->reply((int) $first->getId());
        self::assertCount(1, $this->refresh($first)->getToolboxSnapshot() ?? []);

        // The next message, chosen with nothing at all.
        $second = $this->engine->ask($chat, 'thanks', ChatOrigin::Web, ChatToolbox::none(ToolboxMode::Tags));
        $this->engine->reply((int) $second->getId());

        self::assertSame([], $this->refresh($second)->getToolboxSnapshot(), 'the second exchange ran with no tools');
        self::assertCount(
            1,
            $this->refresh($first)->getToolboxSnapshot() ?? [],
            'and the first exchange still records the tool it ran with — the choice is per exchange, not a mutable preference',
        );
    }

    /**
     * THE EDGE CASE, at the engine: turning everything off is recorded as a
     * choice, not as an absence.
     *
     * `[]` and `NULL` must not be the same bytes. If they were, "I switched
     * this off" and "I never said" would be indistinguishable, and the next
     * form would put the tool back on by itself.
     */
    public function testTurningEverythingOffIsRecordedAsAChoiceNotAsNothing(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $this->llm->method('chat')->willReturn(new LlmResponse('ok', 'stop', [], [], null, 5));

        $exchange = $this->engine->ask($chat, 'no tools please', ChatOrigin::Web, ChatToolbox::none(ToolboxMode::Tags));
        $this->engine->reply((int) $exchange->getId());

        $fresh = $this->refresh($exchange);
        self::assertSame([], $fresh->getToolboxSnapshot());
        self::assertSame(
            ['mode' => 'tags', 'declared' => []],
            $fresh->getToolboxDeclaration(),
            'the empty choice is stored, mode and all — NULL is reserved for rows that predate chat tools',
        );
    }

    /**
     * An exchange whose tags resolve to nothing is still a valid exchange —
     * the ordinary case, and not an error the way it is for a task.
     */
    public function testTagsThatResolveToNothingLeaveAnExchangeWithNoTools(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();

        $this->llm->expects(self::once())->method('chat')->willReturn(new LlmResponse('ok', 'stop', [], [], null, 5));

        // 'nope' matches no tool — resolved by the controller in production;
        // here the empty toolbox is what an empty declaration produces.
        $exchange = $this->engine->ask($chat, 'hello', ChatOrigin::Web, ChatToolbox::none());
        $this->engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Answered, $this->refresh($exchange)->getStatus());
    }

    /**
     * Recovery derives the *right* turn: a tool turn when one is pending, a
     * reply turn otherwise. Getting this wrong re-asks the model a question it
     * already answered.
     */
    public function testRecoveryDerivesTheOwedTurnFromCommittedState(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));

        self::assertInstanceOf(ChatReplyMessage::class, $this->engine->nextTurnMessage($exchange));

        $this->llm->method('chat')->willReturn(
            new LlmResponse(null, 'tool_calls', [['id' => 'c', 'name' => 'get_weather', 'arguments' => []]], [], null, 5),
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['content' => '56', 'durationMs' => 1]);

        $this->engine->reply((int) $exchange->getId());

        self::assertInstanceOf(
            ChatToolTurnMessage::class,
            $this->engine->nextTurnMessage($this->refresh($exchange)),
            'a pending tool turn is the work item, not another LLM question',
        );
    }

    /**
     * A duplicate tool delivery does nothing — and adding nothing is correct.
     */
    public function testADuplicateToolDeliveryIsDropped(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->newChat();
        $exchange = $this->engine->ask($chat, 'weather?', ChatOrigin::Web, $this->byTag(['weather']));

        $this->llm->method('chat')->willReturn(new LlmResponse('ok', 'stop', [], [], null, 5));
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['content' => '56', 'durationMs' => 1]);

        // No tool turn is pending, so this is a duplicate by construction.
        $this->engine->toolTurn((int) $exchange->getId());

        $this->executor->expects(self::never())->method('execute');
        $this->engine->toolTurn((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Queued, $this->refresh($exchange)->getStatus());
    }

    /**
     * Chat and a run share the tool *machinery*, and this is the assertion that
     * makes "shared" mean something: a snapshot written for a chat exchange is
     * read back by the run engine's own reader, with no chat-specific shape.
     */
    public function testTheSnapshotIsTheRunEnginesOwnShape(): void
    {
        $tool = $this->seedTool('get_weather', ['weather']);

        $snapshot = ToolboxSnapshot::fromDefinitions([$tool]);
        $rebuilt = ChatToolbox::of(ToolboxMode::Tags, ['weather'], $snapshot)->tools();

        self::assertCount(1, $rebuilt);
        self::assertSame('get_weather', $rebuilt[0]->getName());
        self::assertSame($tool->getServer()->getUrl(), $rebuilt[0]->getServer()->getUrl());
        self::assertSame($tool->getSchema(), $rebuilt[0]->getSchema());

        // And the secret stays out of the snapshot (SPEC §7): the env var's
        // NAME travels, never its value.
        self::assertSame($tool->getServer()->getCredVar(), $snapshot[0]['credVar'] ?? null);
        self::assertArrayNotHasKey('credential', $snapshot[0]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param list<string> $tags */
    private function byTag(array $tags): ChatToolbox
    {
        $resolver = static::getContainer()->get(\App\RunEngine\ToolboxResolver::class);
        $tools = $resolver->resolveChat(ToolboxMode::Tags, $tags);

        return ChatToolbox::of(ToolboxMode::Tags, $tags, ToolboxSnapshot::fromDefinitions($tools));
    }

    private function newChat(): Chat
    {
        $chat = new Chat('Tools test');
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    /** @param list<string> $tags */
    private function seedTool(string $name, array $tags): Tool
    {
        $server = $this->em->getRepository(McpServer::class)->findOneBy(['name' => 'testserver']);
        if (!$server instanceof McpServer) {
            $server = new McpServer('testserver', 'http://127.0.0.1:9999/mcp', ServerProtocol::Mcp, null);
            $this->em->persist($server);
        }

        $tool = new Tool($server, $name, 'A test tool', ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]);
        $tool->setTags($tags);
        $this->em->persist($tool);
        $this->em->flush();

        return $tool;
    }

    private function refresh(ChatExchange $exchange): ChatExchange
    {
        $this->em->refresh($exchange);

        return $exchange;
    }

    /** @return list<ChatExchangeEvent> */
    private function transcript(Chat $chat): array
    {
        return static::getContainer()->get(ChatExchangeEventRepository::class)->findTranscript($chat);
    }

    private function eventOfType(ChatExchange $exchange, ChatEventType $type): ?ChatExchangeEvent
    {
        foreach (static::getContainer()->get(ChatExchangeEventRepository::class)->findForExchange($exchange) as $event) {
            if ($type === $event->getType()) {
                return $event;
            }
        }

        return null;
    }

    /** @return list<\Symfony\Component\Messenger\Envelope> */
    private function sentTo(string $lane): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.'.$lane);

        return $transport->getSent();
    }
}
