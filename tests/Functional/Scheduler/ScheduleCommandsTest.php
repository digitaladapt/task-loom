<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduler;

use App\Command\ScheduleRunCommand;
use App\Command\ScheduleTickCommand;
use App\Entity\McpServer;
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
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * The scheduler commands (SPEC §14): the one-shot tick reports what it
 * did without failing on a task-level problem, and the daemon ticks on an
 * interval, exits after --max-ticks, and shuts down gracefully on SIGTERM
 * (the container's stop path).
 */
final class ScheduleCommandsTest extends KernelTestCase
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

        $server = new McpServer('cmd-test-server', 'http://127.0.0.1:1/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);
        $this->em->persist(new Tool($server, 'echo', 'test tool', ['type' => 'object']));
        $this->em->flush();

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

    public function testTickCommandReportsNothingOwed(): void
    {
        $tester = new CommandTester(new ScheduleTickCommand($this->scheduler));

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('nothing owed', $tester->getDisplay());
    }

    public function testTickCommandArmsAndFiresOnRealCursors(): void
    {
        // Arm through a real tick, then move the cursor into the past so a
        // second tick is due. (The command reads the real clock, so the
        // tests drive time in the database, not in the tick.)
        $task = $this->enabledTask('Scheduled', '*/5 * * * *');
        $this->scheduler->tick(new \DateTimeImmutable('2026-09-28 12:00:00', new \DateTimeZone('UTC')));

        $this->em->createQuery('UPDATE App\\Entity\\Task t SET t.nextRunAt = :c WHERE t.id = :id')
            ->setParameter('c', time() - 60)
            ->setParameter('id', $task->getId())
            ->execute();

        $tester = new CommandTester(new ScheduleTickCommand($this->scheduler));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Fired task', $tester->getDisplay());
        self::assertCount(1, $this->lane('llm')->getSent());
    }

    public function testTickCommandReportsADispatchFailureWithoutFailing(): void
    {
        $task = new Task('Broken', 'Do it.', TaskKind::Run, ToolboxMode::Explicit, ['ghost'], TaskAuthor::User);
        $task->setSchedule('*/5 * * * *');
        $this->em->persist($task);
        $this->em->flush();
        $task->enable();
        $this->em->flush();
        $this->scheduler->tick(new \DateTimeImmutable('2026-09-28 12:00:00', new \DateTimeZone('UTC')));

        $this->em->createQuery('UPDATE App\\Entity\\Task t SET t.nextRunAt = :c WHERE t.id = :id')
            ->setParameter('c', time() - 60)->setParameter('id', $task->getId())->execute();

        $tester = new CommandTester(new ScheduleTickCommand($this->scheduler));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit, 'the tick ran; the task-level failure is reported, not thrown');
        self::assertStringContainsString('Failed task', $tester->getDisplay());
    }

    public function testDaemonExitsAfterMaxTicks(): void
    {
        $daemon = new ScheduleRunCommand($this->scheduler, $this->em);
        $tester = new CommandTester($daemon);

        $exit = $tester->execute(['--interval' => '1', '--max-ticks' => '2']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Scheduler daemon running', $tester->getDisplay());
        self::assertStringContainsString('tick every 1s', $tester->getDisplay());
    }

    public function testDaemonStopsGracefullyOnSigterm(): void
    {
        // A real process: the container's stop path is SIGTERM, and the
        // daemon must finish its tick and exit 0 — not die mid-transaction.
        $process = new Process(
            [\PHP_BINARY, 'bin/console', 'app:schedule:run', '--interval=30', '--no-interaction'],
            self::projectDir(),
            ['APP_ENV' => 'test'],
            null,
            30,
        );
        $process->start();

        try {
            $this->waitFor(fn (): bool => str_contains($process->getOutput(), 'Scheduler daemon running'));
            $process->signal(\SIGTERM);
            $process->wait();
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        self::assertSame(0, $process->getExitCode(), 'a deliberate stop is not an error');
        self::assertStringContainsString('Scheduler daemon stopped', $process->getOutput());
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function waitFor(callable $condition, float $timeout = 20.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(100_000);
        }

        self::fail('Timed out waiting for the scheduler daemon to become ready.');
    }

    private function enabledTask(string $title, string $schedule): Task
    {
        $task = new Task($title, 'Do the thing.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $task->setSchedule($schedule);
        $this->em->persist($task);
        $this->em->flush();
        $task->enable();
        $this->em->flush();

        return $task;
    }

    private function lane(string $name): InMemoryTransport
    {
        $lane = static::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $lane);

        return $lane;
    }
}
