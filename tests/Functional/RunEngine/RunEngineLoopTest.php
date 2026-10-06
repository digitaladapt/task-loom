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
use App\RunEngine\RunGraph;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutionException;
use App\RunEngine\ToolExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
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

    /**
     * The failure this guards: a local model repeating itself inside one
     * turn. The first call runs; the repeats are dropped, so the tool is
     * dispatched once and the run does not pay for the same answer twice.
     */
    public function testIdenticalCallsInOneTurnAreDispatchedOnce(): void
    {
        $this->catalogTool('get_transactions');
        $task = $this->enabledTask(toolbox: ['get_transactions'], mode: ToolboxMode::Explicit);

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return 1 === \count($chats)
                    ? $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'get_transactions', 'arguments' => ['day' => 'today']],
                        ['id' => 'c2', 'name' => 'get_transactions', 'arguments' => ['day' => 'today']],
                        ['id' => 'c3', 'name' => 'get_transactions', 'arguments' => ['day' => 'today']],
                        ['id' => 'c4', 'name' => 'get_transactions', 'arguments' => ['day' => 'today']],
                    ])
                    : $this->response(content: 'No transactions.');
            },
        );

        $this->executor->method('validate')->willReturn([]);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(['tool' => 'get_transactions', 'content' => '[]', 'isError' => false, 'durationMs' => 3]);

        $run = $this->engine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());

        // Exactly one dispatch reached the ledger for four requested calls.
        self::assertSame(1, $this->countEvents($run, RunEventType::ToolCall));
        self::assertSame(1, $this->countEvents($run, RunEventType::ToolResult));

        // The raw ask is preserved: all four calls, of which three were dropped.
        $response = $this->eventPayload($run, RunEventType::LlmResponse);
        self::assertSame(['c1', 'c2', 'c3', 'c4'], array_column($response['toolCalls'], 'id'));
        self::assertSame(['c2', 'c3', 'c4'], array_column($response['droppedDuplicates'], 'id'));

        // The replayed assistant message matches the results sent back —
        // one tool_call id, one tool result (the endpoint requires this).
        self::assertSame(['c1'], $this->toolCallIdsIn($chats[1]));
    }

    /**
     * The dedup is exact-match only. Two calls to the same tool with
     * different arguments are different questions and both dispatch.
     */
    public function testCallsWithDifferentArgumentsAreNotDeduped(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => 1 === ++$this->chatCount
                ? $this->response(toolCalls: [
                    ['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 'Reykjavik']],
                    ['id' => 'c2', 'name' => 'get_weather', 'arguments' => ['location' => 'Berlin']],
                ])
                : $this->response(content: 'Both reported.'),
        );
        $this->chatCount = 0;

        $this->executor->method('validate')->willReturn([]);
        $this->executor->expects($this->exactly(2))->method('execute')
            ->willReturn(['tool' => 'get_weather', 'content' => 'ok', 'isError' => false, 'durationMs' => 1]);

        $run = $this->engine()->run($task);

        self::assertSame(2, $this->countEvents($run, RunEventType::ToolCall), 'different arguments are different calls');
    }

    /**
     * Two calls whose arguments differ only in key order are the same call
     * as data — the same rule the digest uses, so the two agree.
     */
    public function testArgumentKeyOrderDoesNotDefeatDedup(): void
    {
        $this->catalogTool('fetch');
        $task = $this->enabledTask(toolbox: ['fetch'], mode: ToolboxMode::Explicit);

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => 1 === ++$this->chatCount
                ? $this->response(toolCalls: [
                    ['id' => 'c1', 'name' => 'fetch', 'arguments' => ['a' => 1, 'b' => 2]],
                    ['id' => 'c2', 'name' => 'fetch', 'arguments' => ['b' => 2, 'a' => 1]],
                ])
                : $this->response(content: 'Done.'),
        );
        $this->chatCount = 0;

        $this->executor->method('validate')->willReturn([]);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(['tool' => 'fetch', 'content' => 'ok', 'isError' => false, 'durationMs' => 1]);

        $run = $this->engine()->run($task);

        $response = $this->eventPayload($run, RunEventType::LlmResponse);
        self::assertSame(['c2'], array_column($response['droppedDuplicates'], 'id'));
    }

    /**
     * The drop is announced, so a suppressed repeat is never silent — but
     * the durable record (the ledger) is what outlives the log line.
     */
    public function testDuplicateDropIsLogged(): void
    {
        $this->catalogTool('get_transactions');
        $task = $this->enabledTask(toolbox: ['get_transactions'], mode: ToolboxMode::Explicit);

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => 1 === ++$this->chatCount
                ? $this->response(toolCalls: [
                    ['id' => 'c1', 'name' => 'get_transactions', 'arguments' => ['day' => 'today']],
                    ['id' => 'c2', 'name' => 'get_transactions', 'arguments' => ['day' => 'today']],
                ])
                : $this->response(content: 'Done.'),
        );
        $this->chatCount = 0;

        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_transactions', 'content' => 'ok', 'isError' => false, 'durationMs' => 1]);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $container = static::getContainer();
        $engine = new RunEngine(
            $this->llm,
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $this->executor,
            $container->get(ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            $logger,
            $container->get(RunGraph::class),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
        );

        $engine->run($task);

        self::assertTrue(
            $handler->hasRecordThatContains('duplicate tool call', Level::Info),
            'the dropped duplicate must be announced, not silently discarded',
        );
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
            $container->get(RunGraph::class),
            ['step_budget' => 2, 'tool_retries' => 0, 'circuit_breaker' => 99],
        );

        $run = $engine->run($task);

        self::assertSame(RunStatus::Incomplete, $run->getStatus());
        $payload = $this->eventPayload($run, RunEventType::Failure);
        self::assertStringContainsString('step budget exhausted', $payload['reason']);
    }

    /**
     * The bug this fixes, end to end: a run whose tail cannot fit the
     * budget used to fail closed with `context_exhausted`. Now the window
     * sheds the oldest exchanges and the run completes — and the shedding
     * is recorded as a `context_trim` ledger row rather than happening
     * silently.
     */
    public function testContextTrimIsRecordedWhenTheWindowShedsTail(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        $this->llm->method('chat')->willReturnCallback(
            fn (): LlmResponse => ++$this->chatCount <= 3
                ? $this->response(toolCalls: [['id' => 'c'.$this->chatCount, 'name' => 'get_weather', 'arguments' => ['location' => 'X']]])
                : $this->response(content: 'Done, with the weather.'),
        );
        $this->chatCount = 0;

        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => str_repeat('w', 200), 'isError' => false, 'durationMs' => 1]);

        $container = static::getContainer();
        $compiler = $container->get(PromptCompiler::class);
        $tools = $container->get(ToolboxResolver::class)->resolve($task);
        $head = $compiler->compile($task, $tools);

        // A budget that the head + tool definitions fit but no exchange can:
        // the window must shed every exchange and still send a valid request.
        $fixedChars = \strlen($head['system']) + \strlen($head['user'])
            + \strlen((string) json_encode($compiler->toolsToOpenAi($tools)));
        $window = new ContextWindow(
            contextLimitTokens: (int) ceil($fixedChars / 3.5) + 5,
            maxToolOutputPct: 15.0,
            windowTailExchanges: 10,
        );

        $engine = new RunEngine(
            $this->llm,
            $compiler,
            $container->get(ToolboxResolver::class),
            $this->executor,
            $window,
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(RunGraph::class),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
        );

        $run = $engine->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus(), 'a run that used to die on its tail now completes');
        self::assertContains(RunEventType::ContextTrim, $this->eventTypes($run));

        $trim = $this->eventPayload($run, RunEventType::ContextTrim);
        self::assertSame(0, $trim['keptExchanges']);
        self::assertGreaterThanOrEqual(1, $trim['droppedExchanges']);
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
            $container->get(RunGraph::class),
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

    private function countEvents(Run $run, RunEventType $type): int
    {
        $count = 0;
        foreach ($run->getEvents() as $event) {
            if ($event->getType() === $type) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The tool_call ids in the assistant message of a request's message
     * list — what the endpoint will demand a matching result for.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<string>
     */
    private function toolCallIdsIn(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            if ('tool' === ($message['role'] ?? null)) {
                $ids[] = (string) $message['tool_call_id'];
            }
        }

        return $ids;
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
