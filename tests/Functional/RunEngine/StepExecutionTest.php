<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Context\ContextWindow;
use App\Entity\ErrorClass;
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
use App\Llm\LlmRequestException;
use App\Llm\LlmResponse;
use App\Repository\RunRepository;
use App\Repository\StepRepository;
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

/**
 * Step execution — the run graph (SPEC §13.3, §13.5): a stepped task runs
 * as a parent run plus one child per step plus the final consumer; a
 * zero-step task runs exactly as v1 (SPEC §13.1).
 *
 * Synchronous coverage drives the same turn cores the standalone path
 * uses, with the LLM and tool dispatch stubbed at their interfaces.
 * Failure is strict and fail-closed: a mid-DAG failure settles the parent
 * with the failing step's error class, and downstream steps and the final
 * consumer never run. (The async-lane graph covers dispatch, drop, and
 * recovery behavior: StepExecutionAsyncTest.)
 */
#[AllowMockObjectsWithoutExpectations]
final class StepExecutionTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)

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
    }

    public function testZeroStepTaskRunsAsSingleStandaloneRun(): void
    {
        // SPEC §13.1: zero-step tasks are byte-identical to v1 — one run,
        // no parent, the task's own brief/toolbox as its constitution.
        $this->catalogTool('summary_tool');
        $task = $this->task('Plain task', ['summary_tool']);
        $this->enable($task);
        $this->llm->method('chat')->willReturn($this->response(content: 'Result.'));

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(RunRole::Standalone, $run->getRole());
        self::assertNull($run->getParent());
        self::assertCount(1, $this->runs());
    }

    public function testSteppedTaskRunsAsGraphAndFinalConsumerSettlesParent(): void
    {
        // The flagship shape (SPEC §13.3): two root steps at level 1, a
        // dependent step at level 2, then the task itself as the final
        // consumer of all step outputs.
        $this->catalogTools('get_weather', 'get_calendar', 'summary_tool');
        $task = $this->task('Morning Briefing', ['summary_tool']);
        $weather = $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $calendar = $this->step($task, 2, 'Calendar', 'Find calendar events.', ['get_calendar']);
        $this->step($task, 3, 'Summary', 'Summarize.', ['get_weather'], [$weather->getId(), $calendar->getId()]);
        $this->enable($task);

        $this->llm->method('chat')->willReturnCallback(
            fn (array $messages): LlmResponse => $this->response(content: $this->answerFor($messages)),
        );

        $parent = $this->engine()->run($task);

        self::assertSame(RunRole::Parent, $parent->getRole());
        self::assertSame(RunStatus::Succeeded, $parent->getStatus());

        // The graph: one child per step, plus the final consumer.
        $children = $this->children($parent);
        self::assertCount(4, $children);
        self::assertSame(
            [RunRole::Step, RunRole::Step, RunRole::Step, RunRole::FinalConsumer],
            array_map(static fn (Run $run): RunRole => $run->getRole(), $children),
        );
        foreach ($children as $child) {
            self::assertSame(RunStatus::Succeeded, $child->getStatus());
        }

        // Step children execute their step's brief; the final consumer runs
        // the task's own brief.
        self::assertSame('Weather', $children[0]->getStep()?->getTitle());
        self::assertSame('Summary', $children[2]->getStep()?->getTitle());
        self::assertNull($children[3]->getStep());

        // The parent carries the final consumer's completion artifact as
        // its own completion (SPEC §13.4).
        $completion = $this->payload($parent, RunEventType::Completion);
        self::assertSame('The briefing: sunny, 3 events.', $completion['result']);
    }

    public function testMidDagFailureSettlesParentAndDownstreamNeverRuns(): void
    {
        // SPEC §13.5: any terminal non-success child settles the parent
        // with its state and error class; downstream steps and the final
        // consumer never run; a queued sibling never starts.
        $this->catalogTools('get_weather', 'get_calendar', 'summary_tool');
        $task = $this->task('Morning Briefing', ['summary_tool']);
        $weather = $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $this->step($task, 2, 'Calendar', 'Find calendar events.', ['get_calendar']);
        $this->step($task, 3, 'Summary', 'Summarize.', ['get_weather'], [$weather->getId()]);
        $this->enable($task);

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(function (array $messages) use (&$chats): LlmResponse {
            $chats[] = $messages;
            if (str_contains($this->briefOf($messages), 'Fetch the weather')) {
                throw LlmRequestException::httpError(500, 'weather server down');
            }

            return $this->response(content: $this->answerFor($messages));
        });

        $parent = $this->engine()->run($task);

        self::assertSame(RunStatus::Failed, $parent->getStatus());
        self::assertSame(ErrorClass::LlmError, $parent->getErrorClass());

        $children = $this->children($parent);
        // Weather ran and failed; Calendar was created but never started;
        // Summary and the final consumer were never dispatched.
        $byRole = $this->byRole($children);
        self::assertCount(2, $byRole['step']);
        self::assertSame(RunStatus::Failed, $byRole['step'][0]->getStatus());
        self::assertSame(RunStatus::Queued, $byRole['step'][1]->getStatus());
        self::assertArrayNotHasKey('final_consumer', $byRole);

        self::assertCount(1, $chats, 'only the failing step reached the model');

        $failure = $this->payload($parent, RunEventType::Failure);
        self::assertStringContainsString('Step "Weather"', $failure['reason']);
        self::assertStringContainsString('llm_error', $failure['reason']);
    }

    public function testMalformedDependencyCannotBeSatisfiedAndBlocksDispatch(): void
    {
        // Defense in depth (SPEC §13.2): the enable gate refuses malformed
        // edges, but a graph that bypassed it (hand-written rows, a restored
        // backup) must fail SAFE — a dependency that is not a real step can
        // never succeed, so the dependent step waits rather than dispatches
        // wrongly, and the graph settles instead of wedging.
        $this->catalogTool('summary_tool');
        $task = $this->task('Bypassed gate', ['summary_tool']);
        $this->step($task, 1, 'Root', 'Root step.', ['summary_tool']);
        $malformed = new Step($task, 2, 'Blocked', 'Blocked step.', ToolboxMode::Explicit, ['summary_tool']);
        static::getContainer()->get(StepRepository::class)->save($malformed);
        // Bypass the object model the way corrupt storage would: a null edge.
        $this->em->getConnection()->executeStatement(
            'UPDATE step SET depends_on = :edges WHERE id = :id',
            ['edges' => '[null]', 'id' => $malformed->getId()],
        );
        // Enable through the repository (fresh instances): the corrupt row
        // is what the engine will read, so keep the identity map clean.
        $taskId = (int) $task->getId();
        $this->em->clear();
        $fresh = $this->em->find(Task::class, $taskId);
        self::assertNotNull($fresh);
        $fresh->enable();
        $this->em->flush();

        $this->llm->method('chat')->willReturn($this->response(content: 'Root done.'));

        $parent = $this->engine()->run($fresh);

        // Root ran; Blocked can never be satisfied, so the graph settles
        // for attention rather than dispatching it or wedging in `running`.
        self::assertTrue($parent->isTerminal(), 'the graph must not wedge');
        self::assertSame(RunStatus::NeedsAttention, $parent->getStatus());
        self::assertSame(ErrorClass::Unknown, $parent->getErrorClass());
        $failure = $this->payload($parent, RunEventType::Failure);
        self::assertStringContainsString('can never be dispatched', $failure['reason']);

        $byRole = $this->byRole($this->children($parent));
        self::assertCount(1, $byRole['step'], 'the blocked step must not be dispatched');
        self::assertSame(RunStatus::Succeeded, $byRole['step'][0]->getStatus());
        self::assertArrayNotHasKey('final_consumer', $byRole);
    }

    public function testStepWithMissingToolFailsLoudlyAtDispatch(): void
    {
        // The empty-toolbox incident class (SPEC §5.1): a step declaring a
        // tool that is not in the catalog fails AS a classified ledger row
        // at dispatch — never an exception out of the transaction — and
        // settles the parent (SPEC §13.5).
        $this->catalogTool('summary_tool');
        $task = $this->task('Ghost step', ['summary_tool']);
        $this->step($task, 1, 'Ghost', 'Use a tool that does not exist.', ['ghost_tool']);
        $this->enable($task);

        $parent = $this->engine()->run($task);

        self::assertSame(RunStatus::Failed, $parent->getStatus());
        self::assertSame(ErrorClass::ToolNotFound, $parent->getErrorClass());

        $children = $this->children($parent);
        self::assertCount(1, $children);
        self::assertSame(RunStatus::Failed, $children[0]->getStatus());
        $failure = $this->payload($children[0], RunEventType::Failure);
        self::assertStringContainsString('not in the catalog', $failure['reason']);
    }

    public function testFinalConsumerWithEmptyToolboxFailsLoudly(): void
    {
        // The task-level toolbox is the final consumer's constitution: if
        // it resolves empty, the final consumer fails as a classified row
        // and the parent settles with it.
        $this->catalogTools('get_weather');
        $task = $this->task('Bad final', []);
        $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);

        $this->llm->method('chat')->willReturn($this->response(content: 'Step done.'));

        $parent = $this->engine()->run($task);

        self::assertSame(RunStatus::Failed, $parent->getStatus());
        self::assertSame(ErrorClass::ToolNotFound, $parent->getErrorClass());

        $byRole = $this->byRole($this->children($parent));
        self::assertCount(1, $byRole['step']);
        self::assertSame(RunStatus::Succeeded, $byRole['step'][0]->getStatus());
        self::assertSame(RunStatus::Failed, $byRole['final_consumer'][0]->getStatus());
        $failure = $this->payload($byRole['final_consumer'][0], RunEventType::Failure);
        self::assertStringContainsString('empty toolbox', $failure['reason']);
    }

    public function testParentRunNeverExecutesTurns(): void
    {
        // A parent is a pure aggregator (SPEC §13.3): no first turn is ever
        // derived for it, and its child count is the only thing it carries.
        $this->catalogTools('get_weather');
        $task = $this->task('Parent check', ['get_weather']);
        $this->step($task, 1, 'Weather', 'Fetch the weather.', ['get_weather']);
        $this->enable($task);
        $this->llm->method('chat')->willReturn($this->response(content: 'Done.'));

        $parent = $this->engine()->run($task);

        self::assertFalse($parent->executesTurns());
        self::assertNull($this->engine()->nextTurnMessage($parent));
        self::assertTrue($parent->isTerminal());
        self::assertNull($parent->getCheckpoint(), 'a parent carries no loop state');
        self::assertSame([], $parent->getToolboxSnapshot(), 'a parent carries no toolbox');
        self::assertCount(2, $this->children($parent), 'one step child + the final consumer');
    }

    // --------------------------------------------------------------- helpers

    private function engine(): RunEngine
    {
        $container = static::getContainer();

        return new RunEngine(
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
        );
    }

    /**
     * The brief the model was asked to complete — the user message is
     * "Complete the following task.\n\n<brief>", so this identifies which
     * child run is asking.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function briefOf(array $messages): string
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (\is_string($content) && str_contains($content, 'Complete the following task.')) {
                return $content;
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function answerFor(array $messages): string
    {
        $brief = $this->briefOf($messages);

        return match (true) {
            str_contains($brief, 'Fetch the weather') => 'Weather: sunny.',
            str_contains($brief, 'Find calendar events') => 'Calendar: 3 events.',
            str_contains($brief, 'Summarize') => 'Summary: sunny, 3 events.',
            default => 'The briefing: sunny, 3 events.',
        };
    }

    /**
     * @param list<string> $toolbox
     */
    private function task(string $title, array $toolbox): Task
    {
        $task = new Task($title, 'Compose the full briefing.', TaskKind::Run, ToolboxMode::Explicit, $toolbox, TaskAuthor::User);
        static::getContainer()->get(\App\Repository\TaskRepository::class)->save($task);

        return $task;
    }

    /**
     * The task, enabled — called after its steps are authored (a step
     * cannot be added to an enabled task, SPEC §13.1).
     */
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
        $this->catalogTools($name);
    }

    private function catalogTools(string ...$names): void
    {
        $server = new McpServer('test-server', 'https://server.example/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);

        foreach ($names as $name) {
            $tool = new Tool($server, $name, 'test tool', [
                'type' => 'object',
                'properties' => ['location' => ['type' => 'string']],
            ], [$server->getName()]);
            $this->em->persist($tool);
        }
        $this->em->flush();
    }

    /** @return list<Run> */
    private function runs(): array
    {
        return $this->em->createQuery('SELECT r FROM App\\Entity\\Run r ORDER BY r.id ASC')->getResult();
    }

    /** @return list<Run> */
    private function children(Run $parent): array
    {
        static::getContainer()->get(RunRepository::class)->getEntityManager()->refresh($parent);

        return static::getContainer()->get(RunRepository::class)->findChildren($parent);
    }

    /**
     * @param list<Run> $runs
     *
     * @return array<string, list<Run>>
     */
    private function byRole(array $runs): array
    {
        $byRole = [];
        foreach ($runs as $run) {
            $byRole[$run->getRole()->value][] = $run;
        }

        return $byRole;
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
