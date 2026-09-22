<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Context\ContextWindow;
use App\Entity\ErrorClass;
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
use App\Repository\RunRepository;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutionException;
use App\RunEngine\ToolExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The run loop (SPEC §3, §5) against the real Doctrine ledger, with the
 * LLM and tool dispatch stubbed at their interfaces. These tests are the
 * enforcement proofs for the robustness core behaviors: justified
 * completion, the frozen toolbox, retry-with-feedback, and the circuit
 * breaker.
 */
#[AllowMockObjectsWithoutExpectations]
final class RunEngineLoopTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->executor = $this->createMock(ToolExecutorInterface::class);
    }

    public function testSimpleCompletionRun(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();
        $this->llm->method('chat')->willReturn(
            $this->response(content: 'The briefing: sunny, 21°C, three meetings.')
        );

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(1, $run->getStepCount());

        $events = $this->eventTypes($run);
        self::assertContains(RunEventType::Completion, $events);
        $completion = $this->eventPayload($run, RunEventType::Completion);
        self::assertSame('The briefing: sunny, 21°C, three meetings.', $completion['result']);
    }

    public function testToolCallFlowPersistsLedger(): void
    {
        $this->catalogTool('get_weather');

        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

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

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(2, $run->getStepCount());

        $events = $this->eventTypes($run);
        self::assertContains(RunEventType::ToolCall, $events);
        self::assertContains(RunEventType::ToolResult, $events);
        self::assertContains(RunEventType::Checkpoint, $events);

        // The toolbox snapshot is frozen at run start (SPEC §4.1)
        $snapshot = $run->getToolboxSnapshot();
        self::assertSame('get_weather', $snapshot[0]['tool']);
        self::assertSame('test-server', $snapshot[0]['server']);

        // The second LLM request saw the tool result (feedback loop)
        self::assertCount(2, $chats);
        $second = $chats[1];
        self::assertSame('tool', $second[3]['role']);
        self::assertSame('sunny', $second[3]['content']);
    }

    public function testToolOutsideToolboxNeverDispatches(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        // The model tries to call a tool that is NOT in the toolbox —
        // classic injected-instruction shape. Must never dispatch.
        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            $this->response(toolCalls: [['id' => 'c1', 'name' => 'task_update', 'arguments' => []]]),
            $this->response(content: 'Could not edit tasks.'),
        );

        $this->executor->expects($this->never())->method('execute');

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        $events = $this->eventTypes($run);
        self::assertContains(RunEventType::ToolValidationError, $events);
        $payload = $this->eventPayload($run, RunEventType::ToolValidationError);
        self::assertSame('task_update', $payload['tool']);
    }

    public function testInvalidArgumentsFeedBackToModel(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 42]]]),
            $this->response(content: 'Fixed it.'),
        );

        $this->executor->method('validate')->willReturn(['location: expected string, got int']);
        $this->executor->expects($this->never())->method('execute');

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        $payload = $this->eventPayload($run, RunEventType::ToolValidationError);
        self::assertStringContainsString('expected string', $payload['detail']);
    }

    public function testToolExecutionRetryThenCircuitBreaker(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        // Always failing execute() with circuit-breaker threshold 3 and
        // tool_retries 2: each LLM turn issues one call → 3 failures trip.
        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]]),
        );

        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willThrowException(
            new ToolExecutionException('server unreachable', ErrorClass::ServerError),
        );

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::NeedsAttention, $run->getStatus());
        self::assertSame(ErrorClass::ServerError, $run->getErrorClass());
        self::assertContains(RunEventType::CircuitBreaker, $this->eventTypes($run));
    }

    public function testStepBudgetExhaustionMarksIncomplete(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]]),
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => 'ok', 'isError' => false, 'durationMs' => 1]);

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
            ['step_budget' => 2, 'tool_retries' => 0, 'circuit_breaker' => 99],
        );

        $run = $engine->run($task);

        self::assertSame(RunStatus::Incomplete, $run->getStatus());
        $payload = $this->eventPayload($run, RunEventType::Failure);
        self::assertStringContainsString('step budget exhausted', $payload['reason']);
    }

    public function testLlmFailureFailsRun(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask();
        $this->llm->method('chat')->willThrowException(
            \App\Llm\LlmRequestException::httpError(500, 'boom'),
        );

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(ErrorClass::LlmError, $run->getErrorClass());
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
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
        );
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

    private function catalogTool(string $name): Tool
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

        return $tool;
    }

    /**
     * @param list<string> $toolbox
     */
    private function enabledTask(array $toolbox = ['get_weather'], ToolboxMode $mode = ToolboxMode::Tags): Task
    {
        $task = new Task(
            title: 'Test task',
            brief: 'Do the test thing.',
            kind: TaskKind::Run,
            toolboxMode: $mode,
            toolbox: ToolboxMode::Explicit === $mode ? $toolbox : ['test-server'],
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

    /** @return array<string, mixed> */
    private function eventPayload(Run $run, RunEventType $type): array
    {
        foreach ($run->getEvents() as $event) {
            if ($event->getType() === $type) {
                return $event->getPayload();
            }
        }

        self::fail("No {$type->value} event in ledger.");
    }
}
