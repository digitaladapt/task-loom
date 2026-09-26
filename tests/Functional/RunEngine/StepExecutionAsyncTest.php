<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Command\RunRequeueCommand;
use App\Context\ContextWindow;
use App\Entity\ErrorClass;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunEvent;
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
use App\RunEngine\ToolExecutionException;
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
            static::getContainer()->get(RunGraph::class),
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

    public function testReconcileRepairsLostMidDagDispatch(): void
    {
        // The graph half of the requeue sweep (SPEC §13.3): a step child
        // whose upstream succeeded but whose dispatch was lost — the shape
        // a non-transactional restore leaves behind (committed state, no
        // carrier, and no turn to re-dispatch because the child run itself
        // was never created). Reconcile re-derives it from committed state.
        $this->catalogTool('get_weather');
        $task = $this->task('Lost advancement', ['get_weather']);
        $first = $this->step($task, 1, 'First', 'Fetch the weather.', ['get_weather']);
        $this->step($task, 2, 'Second', 'Summarize the weather.', ['get_weather'], [$first->getId()]);
        $this->enable($task);

        $graph = static::getContainer()->get(RunGraph::class);
        $parent = $graph->beginGraph($task, async: true);
        $this->transport('llm')->reset();

        // Simulate: First committed succeeded WITH its artifact, but the
        // graph advancement that dispatch is normally part of was lost.
        $firstChild = $this->children($parent)[0];
        $firstChild->markStarted();
        $firstChild->markSucceeded();
        $completion = new RunEvent(RunEventType::Completion);
        $completion->setPayload(['result' => 'Weather: sunny.']);
        $firstChild->appendEvent($completion);
        $this->em->flush();

        // Dry run: reports Second, creates nothing.
        $preview = $graph->reconcileParent($parent, dryRun: true);
        self::assertNotNull($preview);
        self::assertFalse($preview->applied);
        self::assertSame(['Second'], $preview->stepTitles);
        self::assertCount(1, $this->children($parent), 'dry run must create nothing');
        self::assertSame([], iterator_to_array($this->transport('llm')->get()));

        // Apply: Second's child run is created and its first turn dispatched.
        $reconcile = $graph->reconcileParent($parent);
        self::assertNotNull($reconcile);
        self::assertTrue($reconcile->applied);
        self::assertSame(['Second'], $reconcile->stepTitles);
        self::assertCount(1, $reconcile->dispatchedRunIds);

        $children = $this->children($parent);
        self::assertCount(2, $children);
        self::assertSame('Second', $children[1]->getStep()?->getTitle());

        // Exactly one message — Second's first turn, and nothing duplicated.
        self::assertCount(1, $this->transport('llm')->get());

        // Idempotent: with the dispatch committed, a second reconcile owes
        // nothing.
        self::assertNull($graph->reconcileParent($parent));

        // And the repaired graph flows to completion normally.
        $chats = 0;
        $this->llm->method('chat')->willReturnCallback(function () use (&$chats): LlmResponse {
            ++$chats;

            return $this->response(content: 'Done.');
        });
        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());
        self::assertCount(3, $this->children($parent), 'Second ran and the final consumer followed');
        self::assertSame(2, $chats, 'Second and the final consumer: exactly one turn each');
    }

    public function testReconcileRepairsLostSettlement(): void
    {
        // The other lost shape: the failing child's terminal state
        // committed, but the parent's settlement never did. Reconcile
        // settles the parent from the failing child — fail-closed, with
        // the child's error class (SPEC §13.5).
        $this->catalogTool('get_weather');
        $task = $this->task('Lost settlement', ['get_weather']);
        $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);

        $graph = static::getContainer()->get(RunGraph::class);
        $parent = $graph->beginGraph($task, async: true);
        $this->transport('llm')->reset();

        $child = $this->children($parent)[0];
        $child->markStarted();
        $child->markFailed(ErrorClass::ServerError);
        $this->em->flush();

        // Dry run reports the settlement; the parent stays unsettled.
        $preview = $graph->reconcileParent($parent, dryRun: true);
        self::assertNotNull($preview);
        self::assertSame(RunStatus::Failed, $preview->settled);
        $this->em->refresh($parent);
        self::assertFalse($parent->isTerminal(), 'dry run must not settle');

        $reconcile = $graph->reconcileParent($parent);
        self::assertNotNull($reconcile);
        self::assertSame(RunStatus::Failed, $reconcile->settled);

        $this->em->refresh($parent);
        self::assertSame(RunStatus::Failed, $parent->getStatus());
        self::assertSame(ErrorClass::ServerError, $parent->getErrorClass());

        $failure = $this->payload($parent, RunEventType::Failure);
        self::assertStringContainsString('Step "Weather"', $failure['reason']);

        // Settlement dispatches nothing; nothing is owed afterwards.
        self::assertSame([], iterator_to_array($this->transport('llm')->get()));
        self::assertNull($graph->reconcileParent($parent));
    }

    public function testParallelSiblingsInterleaveInTheLedger(): void
    {
        // ROADMAP v1.1: a parallel sibling pair executes concurrently and
        // an interleaved event ordering is observable in the ledger. Both
        // roots are genuinely in flight together — each holds a pending
        // tool turn on the tools lane before either advances — and the
        // run_event id order (insertion order) shows B starting before A
        // finishes. Claim + state checks keep execution exactly-once.
        $this->catalogTool('get_weather');
        $task = $this->task('Interleave', ['get_weather']);
        $this->step($task, 1, 'Alpha', 'Fetch the weather one.', ['get_weather']);
        $this->step($task, 2, 'Beta', 'Fetch the weather two.', ['get_weather']);
        $this->enable($task);

        $chats = 0;
        $this->llm->method('chat')->willReturnCallback(function (array $messages) use (&$chats): LlmResponse {
            ++$chats;
            foreach ($messages as $message) {
                $content = $message['content'] ?? null;
                // The final consumer (its system prompt says so) completes
                // directly; steps call the tool once and then complete.
                if ('system' === ($message['role'] ?? null) && \is_string($content) && str_contains($content, "This run is the task's final consumer")) {
                    return $this->response(content: 'Final.');
                }
                if ('tool' === ($message['role'] ?? null)) {
                    return $this->response(content: 'Done.');
                }
            }

            return $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 'x']]]);
        });

        $tools = 0;
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturnCallback(function () use (&$tools): array {
            ++$tools;

            return ['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 1];
        });

        $parent = $this->engine->start($task);

        // Deliver A's turn, then B's turn: both now hold pending tool turns.
        self::assertTrue($this->deliverOne('llm'));
        self::assertTrue($this->deliverOne('llm'));

        $children = $this->children($parent);
        self::assertCount(2, $children);
        foreach ($children as $child) {
            self::assertFalse($child->isTerminal());
            self::assertNotNull($child->getCheckpoint()['pendingToolTurn'] ?? null, 'both siblings in flight together');
        }
        self::assertCount(2, iterator_to_array($this->transport('tools')->get(2)));

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());

        // The ledger's insertion order shows the interleaving: Alpha's
        // first event precedes Beta's first event, which precedes Alpha's
        // completion — neither sibling ran to completion before the other
        // started.
        $rows = $this->em->getConnection()->executeQuery(
            'SELECT id, run_id, type FROM run_event WHERE run_id IN (SELECT id FROM run WHERE parent_id = ?) ORDER BY id',
            [(int) $parent->getId()],
        )->fetchAllAssociative();

        $firstEvent = [];
        $completion = [];
        foreach ($rows as $row) {
            $runId = (int) $row['run_id'];
            $firstEvent[$runId] ??= (int) $row['id'];
            if ('completion' === $row['type']) {
                $completion[$runId] = (int) $row['id'];
            }
        }

        $alphaId = (int) $children[0]->getId();
        $betaId = (int) $children[1]->getId();
        self::assertLessThan($firstEvent[$betaId], $firstEvent[$alphaId], 'Alpha started first');
        self::assertLessThan($completion[$alphaId], $firstEvent[$betaId], 'Beta started before Alpha finished — interleaved');

        // Exactly-once: 2 tool turns per step (call + completion turn) for
        // two steps, plus one turn for the final consumer; 2 tool executes.
        self::assertSame(5, $chats);
        self::assertSame(2, $tools);

        // Nothing left to recover.
        self::assertSame([], iterator_to_array($this->transport('llm')->get()));
        self::assertSame([], iterator_to_array($this->transport('tools')->get()));
    }

    public function testCircuitBreakerInStepSettlesParentIntoAttentionQueue(): void
    {
        // ROADMAP v1.1 / SPEC §13.5: a dead tool server trips the circuit
        // breaker in a step; the parent lands in the attention queue with
        // the precise diagnosis (which step, which error class) — never a
        // silently degraded run.
        $this->catalogTool('get_weather');
        $task = $this->task('Breaker', ['get_weather']);
        $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]]),
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willThrowException(
            new ToolExecutionException('weather server down', ErrorClass::ServerError),
        );

        $this->engine->start($task);
        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::NeedsAttention, $parent->getStatus());
        self::assertSame(ErrorClass::ServerError, $parent->getErrorClass());

        $children = $this->children($parent);
        self::assertCount(1, $children, 'no final consumer for a failed graph');
        self::assertSame(RunStatus::NeedsAttention, $children[0]->getStatus());
        self::assertContains(RunEventType::CircuitBreaker, array_map(
            static fn (RunEvent $event): RunEventType => $event->getType(),
            $children[0]->getEvents()->toArray(),
        ));

        // The attention queue (SPEC §8) carries the parent — the unit the
        // operator acts on — with its error class.
        $attention = static::getContainer()->get(RunRepository::class)->findAttention();
        $attentionIds = array_map(static fn (Run $run): int => (int) $run->getId(), $attention);
        self::assertContains((int) $parent->getId(), $attentionIds);
    }

    public function testRequeueCommandRepairsLostGraphAdvancement(): void
    {
        // ROADMAP v1.1 exit criterion, end to end: a lost dispatch
        // (simulated) is repaired by the requeue sweep — no step wedged,
        // no duplicate execution. Here the LOST thing is the graph
        // advancement itself: the first step committed succeeded with its
        // artifact, but no successor child was ever created (the shape a
        // non-transactional restore leaves). `app:run:requeue` must
        // re-derive it from committed state, exactly once, and the graph
        // must flow to completion.
        $this->catalogTool('get_weather');
        $task = $this->task('Command reconcile', ['get_weather']);
        $first = $this->step($task, 1, 'First', 'Fetch the weather.', ['get_weather']);
        $this->step($task, 2, 'Second', 'Summarize the weather.', ['get_weather'], [$first->getId()]);
        $this->enable($task);

        $parent = $this->engine->start($task);
        $this->transport('llm')->reset(); // whatever was dispatched is lost

        // First step's run committed succeeded with its artifact; the
        // advancement that creates Second never did.
        $firstChild = $this->children($parent)[0];
        $firstChild->markStarted();
        $firstChild->markSucceeded();
        $completion = new RunEvent(RunEventType::Completion);
        $completion->setPayload(['result' => 'Weather: sunny.']);
        $firstChild->appendEvent($completion);
        $this->em->flush();

        $this->llm->method('chat')->willReturnCallback(function (array $messages): LlmResponse {
            foreach ($messages as $message) {
                if ('tool' === ($message['role'] ?? null)) {
                    return $this->response(content: 'Done.');
                }
            }

            return $this->response(content: 'Second done.');
        });

        $tester = new CommandTester(new RunRequeueCommand(
            static::getContainer()->get(RunRepository::class),
            $this->engine,
            $this->bus(),
            static::getContainer()->get(RunGraph::class),
        ));

        // Dry run: reports the owed step; creates nothing.
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('would dispatch 1 step run(s)', $tester->getDisplay());
        self::assertCount(1, $this->children($parent), 'dry run must create nothing');

        // Real sweep: Second's child run is created, its turn dispatched.
        $tester->execute([]);
        self::assertStringContainsString('Graph reconcile: 1 step run(s)', $tester->getDisplay());
        self::assertCount(2, $this->children($parent));

        // Exactly one message for the repaired child — the sweep did not
        // also re-dispatch it as an owed turn.
        self::assertCount(1, iterator_to_array($this->transport('llm')->get(5)));

        $this->pump();

        $this->em->clear();
        $parent = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());
        self::assertCount(3, $this->children($parent), 'Second ran, then the final consumer');

        // A second sweep is a no-op — idempotent recovery, nothing wedged,
        // nothing duplicated. (The settled graph is no longer an active
        // parent, so the graph sweep has nothing to even report.)
        $tester->execute([]);
        self::assertStringContainsString('0 run(s) requeued', $tester->getDisplay());
        self::assertSame([], iterator_to_array($this->transport('llm')->get(5)));
        self::assertCount(3, $this->children($parent));
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
