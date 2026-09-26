<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Command\RunNowCommand;
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
use App\Repository\RunRepository;
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

/**
 * The run-now command (SPEC §8): argument handling, task lookup, the
 * enabled gate, engine execution, and the exit-code contract. The command
 * is built with its real repository wiring; the LLM and tool dispatch are
 * stubbed at their interfaces (no network — the live e2e covers the wires).
 */
#[AllowMockObjectsWithoutExpectations]
final class RunNowCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->executor = $this->createMock(ToolExecutorInterface::class);
    }

    public function testRunNowSucceedsAndPersistsLedger(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();

        $this->llm->method('chat')->willReturn($this->response(content: 'Brief done.'));

        $tester = $this->tester();
        $exit = $tester->execute(['task-id' => (string) $task->getId()]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('succeeded', $tester->getDisplay());

        // The ledger persisted the run and its completion event.
        $runs = $this->em->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
        self::assertCount(1, $runs);
        $run = $runs[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame($task->getId(), $run->getTask()->getId());

        $types = [];
        foreach ($run->getEvents() as $event) {
            $types[] = $event->getType();
        }
        self::assertContains(RunEventType::Completion, $types);
    }

    public function testRunNowUnknownTaskFailsCleanly(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['task-id' => '999999']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No task with id 999999', $tester->getDisplay());
    }

    public function testRunNowDisabledTaskRefusesToRun(): void
    {
        // The enabled gate (SPEC §4.2): only enabled tasks run; drafts must not.
        $task = $this->draftTask();

        $this->llm->expects($this->never())->method('chat');

        $tester = $this->tester();
        $exit = $tester->execute(['task-id' => (string) $task->getId()]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('not enabled', $tester->getDisplay());

        // No run rows were created.
        $runs = $this->em->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
        self::assertCount(0, $runs);
    }

    public function testRunNowNonSucceededRunFailsExitCode(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();

        // LLM transport failure → run ends failed → command exits non-zero.
        $this->llm->method('chat')->willThrowException(
            \App\Llm\LlmRequestException::transport(new \RuntimeException('boom')),
        );

        $tester = $this->tester();
        $exit = $tester->execute(['task-id' => (string) $task->getId()]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('failed', $tester->getDisplay());
    }

    public function testSteppedRunReportsGraphShapeAndSucceedsSynchronously(): void
    {
        // SPEC §13.3 via the command path: a stepped task runs as a graph,
        // and the command reports the child runs — the parent's status is
        // the graph's status, exit code included.
        $this->catalogTool('get_weather');
        $task = $this->steppedTask();

        $this->llm->method('chat')->willReturn($this->response(content: 'Delivered.'));

        $tester = $this->tester();
        $exit = $tester->execute(['task-id' => (string) $task->getId()]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('1 step run(s)', $tester->getDisplay());

        // The parent + its two children are all that persist.
        $runs = $this->em->createQuery('SELECT r FROM App\Entity\Run r ORDER BY r.id ASC')->getResult();
        self::assertCount(3, $runs);
        self::assertSame(RunRole::Parent, $runs[0]->getRole());
        self::assertSame(RunStatus::Succeeded, $runs[0]->getStatus());
        self::assertSame(RunRole::Step, $runs[1]->getRole());
        self::assertSame(RunRole::FinalConsumer, $runs[2]->getRole());
    }

    public function testSteppedRunFailsWithNonZeroExitWhenAStepFails(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->steppedTask();

        $this->llm->method('chat')->willThrowException(
            \App\Llm\LlmRequestException::transport(new \RuntimeException('boom')),
        );

        $tester = $this->tester();
        $exit = $tester->execute(['task-id' => (string) $task->getId()]);

        self::assertSame(1, $exit, $tester->getDisplay());
        self::assertStringContainsString('failed', $tester->getDisplay());
    }

    // --------------------------------------------------------------- helpers

    private function tester(): CommandTester
    {
        $container = static::getContainer();
        $engine = new RunEngine(
            $this->llm,
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $this->executor,
            $container->get(ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(RunGraph::class),
            ['step_budget' => 10, 'tool_retries' => 1, 'circuit_breaker' => 3],
        );

        return new CommandTester(new RunNowCommand(
            $container->get(TaskRepository::class),
            $container->get(RunRepository::class),
            $engine,
            $container->get(MessageBusInterface::class),
        ));
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

    private function draftTask(): Task
    {
        $task = new Task(
            title: 'Draft task',
            brief: 'Not enabled yet.',
            kind: TaskKind::Run,
            toolboxMode: ToolboxMode::Tags,
            toolbox: ['test-server'],
            createdBy: TaskAuthor::User,
        );
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /**
     * A task with one step, then enabled — the graph shape the command
     * must run and report (SPEC §13.3). Steps are authored before the
     * enable (a step cannot be added to an enabled task, §13.1).
     */
    private function steppedTask(): Task
    {
        $task = new Task(
            title: 'Stepped task',
            brief: 'Compose the full briefing.',
            kind: TaskKind::Run,
            toolboxMode: ToolboxMode::Tags,
            toolbox: ['test-server'],
            createdBy: TaskAuthor::User,
        );
        $this->em->persist($task);

        $step = new Step($task, 1, 'Fetch', 'Fetch the weather.', ToolboxMode::Tags, ['test-server']);
        $this->em->persist($step);

        $task->enable();
        $this->em->flush();

        return $task;
    }
}
