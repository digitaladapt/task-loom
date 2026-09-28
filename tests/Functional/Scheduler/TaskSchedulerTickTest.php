<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduler;

use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\RunTrigger;
use App\Entity\ServerProtocol;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use App\RunEngine\RunLauncher;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutorInterface;
use App\Scheduler\ScheduleExpression;
use App\Scheduler\TaskScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The scheduler tick (SPEC §14), over the real database and the real
 * queue path: arming, firing, the at-most-once cursor, the overlap guard,
 * downtime catch-up, and the classified-failure path.
 *
 * The LLM and tool executor are stubbed at their interfaces (no network —
 * the live smoke covers the wires); everything else is real, including
 * RunLauncher and the engine's run creation.
 */
final class TaskSchedulerTickTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskScheduler $scheduler; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();

        $this->em->createQuery('DELETE FROM App\\Entity\\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Run')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Step')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->catalogTool('echo');

        $engine = new RunEngine(
            $container->get(LlmClientInterface::class),
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $container->get(ToolExecutorInterface::class),
            $container->get(\App\Context\ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(RunGraph::class),
            ['step_budget' => 5, 'tool_retries' => 1, 'circuit_breaker' => 3],
            $container->get(MessageBusInterface::class),
        );
        $container->set(RunEngine::class, $engine);

        $this->scheduler = new TaskScheduler(
            $container->get(TaskRepository::class),
            $container->get(RunRepository::class),
            new RunLauncher($engine, $container->get(MessageBusInterface::class)),
            new ScheduleExpression(),
            $this->em,
            new NullLogger(),
            'UTC',
        );
    }

    public function testFreshTaskIsArmedNotFired(): void
    {
        $task = $this->enabledTask('Arm me', '*/15 * * * *');
        $now = $this->at('2026-09-28 12:00:00');

        $result = $this->scheduler->tick($now);

        self::assertCount(1, $result->armed);
        self::assertCount(0, $result->fired, 'a freshly armed task starts on its schedule; it does not fire retroactively');

        $this->em->refresh($task);
        self::assertSame($now->modify('+15 minutes')->getTimestamp(), $task->getNextRunAt());
    }

    public function testNotDueNothingHappens(): void
    {
        $this->enabledTask('Wait', '*/15 * * * *');
        $now = $this->at('2026-09-28 12:00:00');
        $this->scheduler->tick($now);

        $result = $this->scheduler->tick($now->modify('+5 minutes'));

        self::assertTrue($result->isEmpty());
    }

    public function testDueTaskFiresOnceDispatchesAndCarriesTheTrigger(): void
    {
        $task = $this->enabledTask('Fire me', '*/15 * * * *');
        $now = $this->at('2026-09-28 12:00:00');
        $this->scheduler->tick($now); // arm

        $result = $this->scheduler->tick($now->modify('+15 minutes'));

        self::assertCount(1, $result->fired);
        $run = $result->fired[0]['run'];
        $this->em->refresh($run);

        self::assertSame(RunTrigger::Scheduled, $run->getTriggeredBy(), 'the ledger records WHY the run happened');
        self::assertSame(RunStatus::Queued, $run->getStatus());

        // The first turn is on the llm lane — the run is carried by workers, not the tick.
        $lane = $this->lane('llm');
        self::assertCount(1, $lane->getSent());

        // The cursor advanced to the next occurrence.
        $this->em->refresh($task);
        self::assertSame($now->modify('+30 minutes')->getTimestamp(), $task->getNextRunAt());
    }

    public function testTheSameTickMomentDoesNotFireTwice(): void
    {
        $this->enabledTask('Once', '*/15 * * * *');
        $now = $this->at('2026-09-28 12:00:00');
        $this->scheduler->tick($now);
        $this->scheduler->tick($now->modify('+15 minutes'));

        // At-most-once: re-observing the same due moment must not consume
        // it again (the cursor compare-and-swap already moved it).
        $again = $this->scheduler->tick($now->modify('+15 minutes'));

        self::assertCount(0, $again->fired);
        self::assertCount(0, $again->skipped);
        self::assertTrue($again->isEmpty());
    }

    public function testDueOccurrenceIsHeldWhileAPreviousRunIsActiveThenCatchesUp(): void
    {
        $task = $this->enabledTask('Overlap', '*/15 * * * *');
        $now = $this->at('2026-09-28 12:00:00');
        $this->scheduler->tick($now);

        $first = $this->scheduler->tick($now->modify('+15 minutes'));
        self::assertCount(1, $first->fired);
        $firstRun = $first->fired[0]['run'];

        // The 12:30 occurrence arrives while the 12:15 run is still active.
        $held = $this->scheduler->tick($now->modify('+30 minutes'));
        self::assertCount(0, $held->fired, 'two overlapping runs of one task is duplicate work by default');
        self::assertCount(1, $held->skipped);
        self::assertStringContainsString('still active', $held->skipped[0]['reason']);

        // The occurrence stays owed: once the previous run settles, the very
        // next tick fires it — a catch-up, not a skip.
        $firstRun->markSucceeded();
        $this->em->flush();

        $catchUp = $this->scheduler->tick($now->modify('+31 minutes'));
        self::assertCount(1, $catchUp->fired);

        // Missed slots collapse into the one catch-up fire: the cursor is
        // the next occurrence after NOW, not after the missed slot.
        $this->em->refresh($task);
        self::assertSame($now->modify('+45 minutes')->getTimestamp(), $task->getNextRunAt());
    }

    public function testDowntimeCatchesUpWithOneFireNotOnePerMissedSlot(): void
    {
        $task = $this->enabledTask('Catch up', '0 * * * *'); // hourly
        $now = $this->at('2026-09-28 12:00:00');

        // Simulate a cursor armed two days ago (the daemon was down).
        $this->em->createQuery('UPDATE App\\Entity\\Task t SET t.nextRunAt = :c WHERE t.id = :id')
            ->setParameter('c', $now->modify('-2 days')->getTimestamp())
            ->setParameter('id', $task->getId())
            ->execute();
        $this->em->clear();

        $result = $this->scheduler->tick($now);

        self::assertCount(1, $result->fired, 'downtime never skips a slot by accident — but it does not replay every missed slot either');

        $task = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $task);
        self::assertSame($now->modify('+1 hour')->getTimestamp(), $task->getNextRunAt());
    }

    public function testUnscheduledTaskIsNeverTouched(): void
    {
        $this->enabledTask('Manual only', null);
        $now = $this->at('2026-09-28 12:00:00');

        $result = $this->scheduler->tick($now->modify('+2 hours'));

        self::assertTrue($result->isEmpty());
    }

    public function testDisabledTaskIsNeverTouched(): void
    {
        $task = $this->draftTask('Not enabled', '*/5 * * * *');
        $now = $this->at('2026-09-28 12:00:00');

        $result = $this->scheduler->tick($now);

        self::assertTrue($result->isEmpty());
        $this->em->refresh($task);
        self::assertNull($task->getNextRunAt(), 'a draft is never armed');
    }

    public function testAnEmptyToolboxFailsLoudlyAsAClassifiedLedgerRow(): void
    {
        // A scheduled task whose toolbox no longer resolves: the occurrence
        // happens and fails — recorded, classified, consumed. No silent
        // skip, no retry storm.
        $task = new Task('Broken toolbox', 'Do it.', TaskKind::Run, ToolboxMode::Explicit, ['ghost_tool'], TaskAuthor::User);
        $task->setSchedule('*/15 * * * *');
        $this->em->persist($task);
        $this->em->flush();
        $task->enable();
        $this->em->flush();

        $now = $this->at('2026-09-28 12:00:00');
        $this->scheduler->tick($now); // arm

        $result = $this->scheduler->tick($now->modify('+15 minutes'));

        self::assertCount(1, $result->failed);
        $run = $result->failed[0]['run'];
        $this->em->refresh($run);

        self::assertSame(RunTrigger::Scheduled, $run->getTriggeredBy());
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(\App\Entity\ErrorClass::ToolNotFound, $run->getErrorClass());

        // The failure is in the attempt ledger, with its class.
        $types = array_map(static fn ($e): RunEventType => $e->getType(), $run->getEvents()->toArray());
        self::assertContains(RunEventType::Failure, $types);

        // The occurrence was consumed: the cursor advanced.
        $this->em->refresh($task);
        self::assertSame($now->modify('+30 minutes')->getTimestamp(), $task->getNextRunAt());
    }

    public function testSteppedTaskFiresAsAGraphThroughTheSameQueuePath(): void
    {
        $task = $this->draftTask('Stepped schedule', '*/15 * * * *');
        $step = new \App\Entity\Step($task, 1, 'Fetch', 'Fetch the thing.', ToolboxMode::Explicit, ['echo']);
        $this->em->persist($step);
        $this->em->flush();
        $task->enable();
        $this->em->flush();

        $now = $this->at('2026-09-28 12:00:00');
        $this->scheduler->tick($now); // arm
        $result = $this->scheduler->tick($now->modify('+15 minutes'));

        self::assertCount(1, $result->fired);
        $parent = $result->fired[0]['run'];
        $this->em->refresh($parent);

        self::assertSame(\App\Entity\RunRole::Parent, $parent->getRole(), 'a stepped task fires as a graph');
        self::assertSame(RunTrigger::Scheduled, $parent->getTriggeredBy());
        self::assertCount(1, $this->lane('llm')->getSent(), 'the root step\'s first turn is on the lane');
    }

    private function catalogTool(string $name): void
    {
        $server = new McpServer('scheduler-test-server', 'http://127.0.0.1:1/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);
        $this->em->persist(new Tool($server, $name, 'test tool', ['type' => 'object']));
        $this->em->flush();
    }

    private function enabledTask(string $title, ?string $schedule): Task
    {
        $task = $this->draftTask($title, $schedule);
        $task->enable();
        $this->em->flush();

        return $task;
    }

    private function draftTask(string $title, ?string $schedule): Task
    {
        $task = new Task($title, 'Do the thing.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $task->setSchedule($schedule);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    private function lane(string $name): InMemoryTransport
    {
        $lane = static::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $lane);

        return $lane;
    }
}
