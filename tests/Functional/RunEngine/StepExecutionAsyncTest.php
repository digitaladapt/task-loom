<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Command\RunRequeueCommand;
use App\Context\ContextWindow;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\ServerProtocol;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\LlmTurnMessage;
use App\Repository\RunRepository;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
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
 * The step graph over the async lanes (SPEC §13.3): beginGraph dispatches
 * every root step's first turn in the creation transaction; terminal
 * commits derive and enqueue the next children on the shared connection;
 * duplicates and late redeliveries are dropped; a sibling's failure leaves
 * queued orphans that never start; the requeue sweep re-fires owed turns.
 *
 * The lanes are in-memory transports with the same stamps a real worker
 * attaches, so dispatch, routing, claim, and dedup are the production
 * paths (the same caveat as RunEngineAsyncFlowTest: the shared-connection
 * transaction itself is production-only).
 */
#[AllowMockObjectsWithoutExpectations]
final class StepExecutionAsyncTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\\Entity\\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Run')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Step')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Task')->execute();
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
            $container->get(RunGraph::class),
            ['step_budget' => 20, 'tool_retries' => 1, 'circuit_breaker' => 3],
            $this->bus(),
        );

        // Install BEFORE anything is dispatched: handlers are built lazily
        // on first delivery and resolve RunEngine through DI.
        $container->set(RunEngine::class, $this->engine);
    }

    public function testGraphFlowsThroughBothLanesToCompletion(): void
    {
        // Two root steps + a dependent + the final consumer, driven by
        // start() and worker deliveries only (no inline loop anywhere).
        $this->catalogTool('get_weather');
        $task = $this->task('Async briefing', ['get_weather']);
        $first = $this->step($task, 1, 'First', 'Fetch the weather.', ['get_weather']);
        $this->step($task, 2, 'Second', 'Summarize the weather.', ['get_weather'], [$first->getId()]);
        $this->enable($task);

        $this->llm->method('chat')->willReturnCallback(
            fn (array $messages): LlmResponse => $this->response(content: $this->answerFor($messages)),
        );

        $parent = $this->engine->start($task);

        self::assertSame(RunRole::Parent, $parent->getRole());
        self::assertFalse($parent->isTerminal(), 'a fresh graph is active');

        // beginGraph dispatched exactly the root step's first turn.
        $llmLane = $this->transport('llm');
        self::assertCount(1, $llmLane->getSent());
        self::assertInstanceOf(LlmTurnMessage::class, $llmLane->getSent()[0]->getMessage());

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());

        $children = $this->children($parent);
        self::assertCount(3, $children, 'two steps + the final consumer');
        foreach ($children as $child) {
            self::assertSame(RunStatus::Succeeded, $child->getStatus());
        }

        // Both lanes drained; the final consumer's artifact is the run's.
        self::assertSame([], iterator_to_array($this->transport('llm')->get()));
        self::assertSame([], iterator_to_array($this->transport('tools')->get()));
        $completion = $this->payload($parent, RunEventType::Completion);
        self::assertSame('Final: sunny.', $completion['result']);
    }

    public function testFinalConsumerHeadCarriesStepOutputsThroughTheLanes(): void
    {
        // The Inputs block (SPEC §13.4), async: each step's output is
        // committed terminal, THEN the final consumer is created inside that
        // same terminal commit with those outputs frozen into its head — so
        // what the final consumer's prompt contains is exactly what the
        // committed ledger says, driven by worker deliveries only.
        $this->catalogTool('get_weather');
        $task = $this->task('Async inputs', ['get_weather']);
        $first = $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $this->step($task, 2, 'Calendar', 'Find calendar events.', ['get_weather'], [$first->getId()]);
        $this->enable($task);

        $this->llm->method('chat')->willReturnCallback(
            fn (array $messages): LlmResponse => $this->response(content: $this->answerFor($messages)),
        );

        $this->engine->start($task);
        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());

        $final = null;
        foreach ($this->children($parent) as $child) {
            if (RunRole::FinalConsumer === $child->getRole()) {
                $final = $child;
                break;
            }
        }
        self::assertInstanceOf(Run::class, $final);

        $head = $final->getCheckpoint()['promptHead'] ?? null;
        self::assertIsArray($head);
        $system = (string) $head['system'];

        self::assertStringContainsString('## Inputs', $system);
        self::assertStringContainsString('### Weather', $system);
        self::assertStringContainsString('Step: sunny.', $system);
        self::assertStringContainsString('### Calendar', $system);
    }

    public function testSiblingFailureLeavesQueuedOrphansAndSettlesParent(): void
    {
        // Both root steps are dispatched at once. The first fails; the
        // second's message is already on the lane — its delivery must be
        // dropped (the orphan never starts, SPEC §13.5).
        $this->catalogTool('get_weather');
        $task = $this->task('Async failure', ['get_weather']);
        $this->step($task, 1, 'Doomed', 'Fetch the weather.', ['get_weather']);
        $this->step($task, 2, 'Innocent', 'Summarize the weather.', ['get_weather']);
        $this->enable($task);

        $chats = 0;
        $this->llm->method('chat')->willReturnCallback(function () use (&$chats): LlmResponse {
            ++$chats;
            if (1 === $chats) {
                throw \App\Llm\LlmRequestException::httpError(500, 'weather down');
            }

            return $this->response(content: 'should not run');
        });

        $parent = $this->engine->start($task);

        // Two root-step messages dispatched together.
        self::assertCount(2, $this->transport('llm')->getSent());

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Failed, $parent->getStatus());

        $byRole = [];
        foreach ($this->children($parent) as $child) {
            $byRole[$child->getRole()->value][] = $child;
        }
        self::assertCount(2, $byRole['step']);
        self::assertSame([RunStatus::Failed, RunStatus::Queued], array_map(
            static fn (Run $run): RunStatus => $run->getStatus(),
            $byRole['step'],
        ), 'the orphan stays queued — never started, never run');
        self::assertArrayNotHasKey('final_consumer', $byRole);
        self::assertSame(1, $chats, 'only one step reached the model');
    }

    public function testDuplicateDeliveryOfAChildTurnIsDropped(): void
    {
        // At-least-once transport: the same LlmTurnMessage delivered twice
        // executes once (claim + committed state adjudicate the duplicate).
        $this->catalogTool('get_weather');
        $task = $this->task('Async dedup', ['get_weather']);
        $this->step($task, 1, 'Only', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);

        $chats = 0;
        $this->llm->method('chat')->willReturnCallback(function () use (&$chats): LlmResponse {
            ++$chats;

            return $this->response(content: 'Done.');
        });

        $parent = $this->engine->start($task);
        $children = $this->children($parent);
        $childId = (int) $children[0]->getId();

        $this->deliverOne('llm');
        self::assertSame(1, $chats);

        // Redeliver the exact message: dropped against committed state.
        // The settled child's commit advanced the graph, so the final
        // consumer's message sits ahead of the duplicate on the lane —
        // deliver both and count requests.
        $this->bus()->dispatch(new LlmTurnMessage($childId, 1));
        $this->deliverOne('llm');   // the final consumer (2nd real request)
        $this->deliverOne('llm');   // the duplicate — must be dropped
        self::assertSame(2, $chats, 'the duplicate must not reach the model');

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());
        // One step run + final consumer, each exactly one LLM request.
        self::assertSame(2, $chats);
    }

    public function testRequeueSweepRecoversAGraphAfterPurge(): void
    {
        // A purged lane loses a delivered-but-uncommitted turn's successor.
        // The run row is the durable queue: the owed child turn is
        // re-derived from committed state and re-dispatched (SPEC §13.3).
        $this->catalogTool('get_weather');
        $task = $this->task('Async requeue', ['get_weather']);
        $this->step($task, 1, 'Step', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);

        $this->llm->method('chat')->willReturn($this->response(content: 'Done.'));

        $parent = $this->engine->start($task);
        $children = $this->children($parent);
        $child = $children[0];

        // Deliver the child's first LLM turn but simulate the crash before
        // commit: purge the lane so nothing carries the run onward.
        $this->transport('llm')->reset();

        $tester = new CommandTester(new RunRequeueCommand(
            static::getContainer()->get(RunRepository::class),
            $this->engine,
            $this->bus(),
        ));
        $tester->execute([]);

        // The child run is active and over its budget-window state: the
        // sweep re-derives its owed turn (step 1, nothing pending).
        self::assertStringContainsString('requeued', $tester->getDisplay());

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());
    }

    public function testParentNeverReceivesATurnMessage(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->task('Async parent', ['get_weather']);
        $this->step($task, 1, 'Step', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);
        $this->llm->method('chat')->willReturn($this->response(content: 'Done.'));

        $parent = $this->engine->start($task);

        // Only children are ever dispatched: the parent owes no turn, and
        // the sweep agrees.
        self::assertNull($this->engine->nextTurnMessage($parent));

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());
        self::assertCount(2, $this->children($parent));
    }

    // --------------------------------------------------------------- helpers

    /**
     * Drive every lane until it settles — what `messenger:consume llm
     * tools` does in production, one message at a time.
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
        return $this->em->createQuery('SELECT r FROM App\\Entity\\Run r ORDER BY r.id ASC')->getResult();
    }

    /** @return list<Run> */
    private function children(Run $parent): array
    {
        $repository = static::getContainer()->get(RunRepository::class);
        $repository->getEntityManager()->refresh($parent);

        return $repository->findChildren($parent);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Run $run, RunEventType $type): array
    {
        $this->em->refresh($run);

        $latest = [];
        foreach ($run->getEvents() as $event) {
            if ($event->getType() === $type) {
                $latest = $event->getPayload();
            }
        }

        return $latest;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function answerFor(array $messages): string
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (\is_string($content) && str_contains($content, 'Compose the full briefing')) {
                return 'Final: sunny.';
            }
        }

        return 'Step: sunny.';
    }

    /**
     * @param list<string> $toolbox
     */
    private function task(string $title, array $toolbox): Task
    {
        $task = new Task($title, 'Compose the full briefing.', TaskKind::Run, ToolboxMode::Explicit, $toolbox, TaskAuthor::User);
        static::getContainer()->get(TaskRepository::class)->save($task);

        return $task;
    }

    private function enable(Task $task): void
    {
        $task->enable();
        $this->em->flush();
    }

    /**
     * @param list<string> $toolbox
     * @param list<int>    $dependsOn
     */
    private function step(Task $task, int $position, string $title, string $brief, array $toolbox, array $dependsOn = []): Step
    {
        $step = new Step($task, $position, $title, $brief, ToolboxMode::Explicit, $toolbox, $dependsOn);
        static::getContainer()->get(StepRepository::class)->save($step);

        return $step;
    }

    private function catalogTool(string $name): void
    {
        $server = new McpServer('test-server', 'https://server.example/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);

        $tool = new Tool($server, $name, 'test tool', [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
        ], [$server->getName()]);
        $this->em->persist($tool);
        $this->em->flush();
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
}
