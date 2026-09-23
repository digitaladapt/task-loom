<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Command\RunNowCommand;
use App\Command\RunRequeueCommand;
use App\Context\ContextWindow;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\ServerProtocol;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\LlmTurnMessage;
use App\Message\ToolTurnMessage;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The async engine end-to-end (SPEC §6): app:run:now --queue creates the run
 * and dispatches the first turn; the two lanes (llm, tools) carry the run to
 * terminal state; duplicates are dropped; lost messages are recovered by
 * app:run:requeue; a dead worker's claim is taken over.
 *
 * The lanes are in-memory transports here, and messages are delivered with
 * the same stamps a real worker attaches — the dispatch, routing, claim, and
 * dedup paths are the production ones. (The one production-only property the
 * in-memory lanes cannot exercise is the same-connection transaction that
 * makes state+successor commit atomic; every state-based dedup path that
 * rides on it is asserted here.)
 */
#[AllowMockObjectsWithoutExpectations]
final class RunEngineAsyncFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private int $chatCount = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->executor = $this->createMock(ToolExecutorInterface::class);

        $container = static::getContainer();
        $this->engine = new RunEngine(
            $this->llm,
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $this->executor,
            $container->get(ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
            $this->bus(),
        );

        // Install BEFORE anything is dispatched: handlers are built lazily
        // on first delivery and resolve RunEngine through DI.
        $container->set(RunEngine::class, $this->engine);
    }

    public function testQueuedRunFlowsThroughBothLanesToCompletion(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return 1 === \count($chats)
                    ? $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 'Reykjavik']]])
                    : $this->response(content: 'Weather: sunny.');
            },
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 12]);

        $tester = new CommandTester(new RunNowCommand(
            static::getContainer()->get(TaskRepository::class),
            $this->engine,
            $this->bus(),
        ));
        $exit = $tester->execute(['task-id' => (string) $task->getId(), '--queue' => true]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('queued', $tester->getDisplay());

        // Queued run + its first turn sitting on the llm lane.
        $runs = $this->runs();
        self::assertCount(1, $runs);
        $run = $runs[0];
        self::assertSame(RunStatus::Queued, $run->getStatus());

        $llmLane = $this->transport('llm');
        self::assertCount(1, $llmLane->getSent());
        self::assertInstanceOf(LlmTurnMessage::class, $llmLane->getSent()[0]->getMessage());

        $this->pump();

        // Terminal, with the full ledger written across the lanes.
        $this->em->clear();
        $run = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(2, $run->getStepCount());

        $types = $this->eventTypes($run);
        self::assertContains(RunEventType::LlmRequest, $types);
        self::assertContains(RunEventType::LlmResponse, $types);
        self::assertContains(RunEventType::ToolCall, $types);
        self::assertContains(RunEventType::ToolResult, $types);
        self::assertContains(RunEventType::Checkpoint, $types);
        self::assertContains(RunEventType::Completion, $types);

        // Both lanes drained; the claim was released.
        self::assertSame([], iterator_to_array($llmLane->get()));
        self::assertSame([], iterator_to_array($this->transport('tools')->get()));
        self::assertNull($this->claimedAt((int) $run->getId()));

        // Two llm requests on the wire (turn 1, turn 2), one tool turn.
        self::assertCount(2, $llmLane->getSent());
        self::assertCount(1, $this->transport('tools')->getSent());

        // The second LLM request saw the tool result (feedback loop intact
        // across workers).
        self::assertCount(2, $chats);
        self::assertSame('tool', $chats[1][3]['role']);
        self::assertSame('sunny', $chats[1][3]['content']);
    }

    public function testDuplicateLlmDeliveryAfterCommitIsDropped(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => 1 === ++$this->chatCount
                ? $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]])
                : $this->response(content: 'Done.'),
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 1]);

        $run = $this->engine->start($task);
        $this->bus()->dispatch(new LlmTurnMessage((int) $run->getId(), 1));

        // First carrier: executes the llm turn, commits the pending tool
        // turn and its lane message.
        $this->deliverOne('llm');
        self::assertSame(1, $this->chatCount);

        // Second carrier of the SAME turn (redelivery after a post-commit
        // crash): must be dropped against committed state — no second
        // request, no forked run.
        $this->bus()->dispatch(new LlmTurnMessage((int) $run->getId(), 1));
        $this->deliverOne('llm');
        self::assertSame(1, $this->chatCount, 'the duplicate llm delivery must not reach the model');

        $this->em->clear();
        self::assertSame(RunStatus::Running, $this->runs()[0]->getStatus());

        // The run still completes normally through the tool lane.
        $this->pump();

        $this->em->clear();
        $run = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(2, $this->chatCount, 'exactly two requests: one per real turn');
        self::assertNull($this->claimedAt((int) $run->getId()));
    }

    public function testRequeueCommandRedispatchsOwedTurnAfterPurge(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => 1 === ++$this->chatCount
                ? $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]])
                : $this->response(content: 'Done.'),
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 1]);

        $run = $this->engine->start($task);
        $this->bus()->dispatch(new LlmTurnMessage((int) $run->getId(), 1));
        $this->deliverOne('llm');

        // Simulate a purged queue: the committed tool turn has no carrier
        // anywhere. This is the loss redelivery cannot repair.
        $this->transport('tools')->reset();

        $tester = new CommandTester(new RunRequeueCommand(
            static::getContainer()->get(RunRepository::class),
            $this->engine,
            $this->bus(),
        ));

        // Dry run first: reports, dispatches nothing.
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('would be requeued', $tester->getDisplay());
        self::assertSame([], iterator_to_array($this->transport('tools')->get()));

        // Real run: the owed ToolTurnMessage is re-derived from state.
        $tester->execute([]);
        self::assertStringContainsString('dispatched', $tester->getDisplay());
        $owed = iterator_to_array($this->transport('tools')->get());
        self::assertCount(1, $owed);
        self::assertInstanceOf(ToolTurnMessage::class, $owed[array_key_first($owed)]->getMessage());

        $this->pump();

        $this->em->clear();
        self::assertSame(RunStatus::Succeeded, $this->runs()[0]->getStatus());

        // Nothing active left: a second requeue is a no-op.
        $tester->execute([]);
        self::assertStringContainsString('0 run(s) requeued', $tester->getDisplay());
    }

    public function testDeadWorkerClaimIsTakenOver(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => 1 === ++$this->chatCount
                ? $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]])
                : $this->response(content: 'Done.'),
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 1]);

        $run = $this->engine->start($task);
        $this->bus()->dispatch(new LlmTurnMessage((int) $run->getId(), 1));
        $this->deliverOne('llm');

        // A worker that died mid-tool-turn left the claim behind: claim is
        // held, and the state (pending tool turn) is committed. Rewind the
        // claim past the staleness window — the next carrier takes over.
        $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :abandoned WHERE id = :id',
            ['abandoned' => time() - 7200, 'id' => $run->getId()],
        );

        $this->deliverOne('tools');
        $this->pump();

        $this->em->clear();
        $run = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(2, $run->getStepCount());
        self::assertNull($this->claimedAt((int) $run->getId()));
    }

    // --------------------------------------------------------------- helpers

    /**
     * Drive every lane until it settles — what
     * `messenger:consume llm tools` does in production, one message at a
     * time, with the stamps a worker attaches.
     */
    private function pump(int $maxDeliveries = 50): void
    {
        for ($i = 0; $i < $maxDeliveries; ++$i) {
            $delivered = $this->deliverOne('llm');
            $delivered = $this->deliverOne('tools') || $delivered;

            if (!$delivered) {
                return;
            }
        }

        self::fail('The lanes did not settle within 50 deliveries.');
    }

    private function deliverOne(string $lane): bool
    {
        $envelopes = iterator_to_array($this->transport($lane)->get());
        if ([] === $envelopes) {
            return false;
        }

        $envelope = $envelopes[array_key_first($envelopes)];
        $this->bus()->dispatch($envelope->with(new ReceivedStamp($lane), new ConsumedByWorkerStamp()));
        $this->transport($lane)->ack($envelope);

        return true;
    }

    private function bus(): MessageBusInterface
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function transport(string $lane): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.'.$lane);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<Run> */
    private function runs(): array
    {
        return $this->em->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
    }

    private function claimedAt(int $runId): ?int
    {
        $value = $this->em->getConnection()->fetchOne('SELECT claimed_at FROM run WHERE id = :id', ['id' => $runId]);

        return \is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}>|null $toolCalls
     */
    private function response(?string $content = null, ?array $toolCalls = null): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            finishReason: null === $toolCalls ? 'stop' : 'tool_calls',
            toolCalls: $toolCalls ?? [],
            usage: ['total_tokens' => 10],
            reasoningContent: null,
            durationMs: 5,
        );
    }

    private function catalogTool(string $name): void
    {
        $server = new McpServer('test-server', 'https://server.example/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);

        $tool = new Tool($server, $name, 'test tool', [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ], [$server->getName()]);
        $this->em->persist($tool);
        $this->em->flush();
    }

    private function enabledTask(): Task
    {
        $task = new Task(
            title: 'Test task',
            brief: 'Do the test thing.',
            kind: TaskKind::Run,
            toolboxMode: ToolboxMode::Tags,
            toolbox: ['test-server'],
            createdBy: TaskAuthor::User,
        );
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /** @return list<RunEventType> */
    private function eventTypes(Run $run): array
    {
        $types = [];
        foreach ($run->getEvents() as $event) {
            $types[] = $event->getType();
        }

        return $types;
    }
}
