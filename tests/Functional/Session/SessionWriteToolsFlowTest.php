<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Context\ContextWindow;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\ServerProtocol;
use App\Entity\SessionDeclarationOutcome;
use App\Entity\SessionMemorySource;
use App\Entity\SessionMemoryTier;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\LlmTurnMessage;
use App\Message\SessionTurnMessage;
use App\Repository\RunRepository;
use App\Repository\SessionMemoryRepository;
use App\RunEngine\LoopState;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolboxSnapshot;
use App\RunEngine\ToolExecutorInterface;
use App\Session\SessionMemoryRenderer;
use App\Session\SessionMemoryStore;
use App\Session\SessionToolRunner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The write tools end to end (docs/design/SESSION_TASKS.md §5, build order
 * step 3): a session run calls `session_note` and `session_objective` through
 * the **real lanes**, the writes land in the store, and the very next
 * request's `## Memories` block carries them — the loop the whole feature
 * exists for, closed.
 *
 * The run is crafted as the slice launcher will craft it and driven on the
 * **session** lane → tools → session with worker stamps (the lane a slice's
 * turns actually ride, docs/design/SESSION_TASKS.md §7); nothing calls a
 * private seam. The tool definitions on the wire are asserted too — a
 * harness tool that is not on the wire is a tool the model cannot call.
 */
#[AllowMockObjectsWithoutExpectations]
final class SessionWriteToolsFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryStore $store; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryRepository $memories; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemoryRevision')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemory')->execute();
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
        $this->memories = $container->get(SessionMemoryRepository::class);
        $this->store = new SessionMemoryStore($this->em, $this->memories, hotCap: 5, coldCap: 25, writeMaxChars: 2000);

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
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
            $this->bus(),
            $container->get(SessionMemoryRenderer::class),
            new SessionToolRunner($this->store),
            $container->get(\App\Session\SessionSliceEngine::class),
        );

        // Install BEFORE anything is dispatched: handlers are built lazily
        // on first delivery and resolve RunEngine through DI.
        $container->set(RunEngine::class, $this->engine);
    }

    public function testTheModelWritesThroughTheToolsAndTheNextRequestCarriesTheWrites(): void
    {
        $task = $this->sessionTask();
        $run = $this->craftRun($task);

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages, array $tools) use (&$chats): LlmResponse {
                $chats[] = [$messages, $tools];

                return match (\count($chats)) {
                    // Turn 1: the model writes both kinds of memory, one call each.
                    1 => $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'session_note', 'arguments' => ['text' => 'Migration 0007 adds the store; the importer consumes it.']],
                        ['id' => 'c2', 'name' => 'session_objective', 'arguments' => ['text' => 'Finish the importer, then harden it.']],
                    ]),
                    // Turn 2: the slice ends — by declaration, alone (step 4).
                    default => $this->response(toolCalls: [
                        ['id' => 'c3', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Importer finished and hardened.']],
                    ]),
                };
            },
        );

        // The MCP executor must never be touched by this run: both calls are
        // harness tools. (No enforcement in the mock — the assertion below on
        // the events proves dispatch took the in-process route.)
        $this->executor->expects($this->never())->method('execute');
        $this->executor->expects($this->never())->method('validate');

        $this->bus()->dispatch(new SessionTurnMessage((int) $run->getId(), 1));
        $this->pump();

        $this->em->clear();
        $run = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertCount(1, $this->runs(), 'a done declaration launches no next slice');
        self::assertSame(SessionDeclarationOutcome::Done, $run->getDeclarationOutcome());
        self::assertCount(2, $chats, 'two requests: one that writes, one that declares');

        // 1. The harness tools rode the wire as definitions.
        $firstTools = array_column(array_column($chats[0][1], 'function'), 'name');
        self::assertContains('session_note', $firstTools);
        self::assertContains('session_objective', $firstTools);

        // 2. The result the model saw for each call, and the ledger's record
        //    of dispatch — origin harness, no server anywhere.
        $results = $this->toolResults($run);
        self::assertCount(3, $results, 'each call produced a result — including the declaration');
        $byTool = [];
        foreach ($results as $result) {
            $byTool[$result['tool']] = $result;
        }
        self::assertStringContainsString('"saved":true', $byTool['session_note']['content']);
        self::assertSame('harness', $byTool['session_note']['origin']);
        self::assertStringContainsString('"revision":1', $byTool['session_objective']['content'], 'a fresh objective reports revision 1');
        self::assertStringContainsString('"declared":"done"', $byTool['session_declare']['content'], 'the declaration is recorded like any harness result');

        // 3. The writes landed in the store, as the session's own.
        $objective = $this->memories->findObjectiveFor((int) $task->getId());
        self::assertNotNull($objective);
        self::assertSame('Finish the importer, then harden it.', $objective->getText());
        self::assertSame(SessionMemorySource::Session, $objective->getSource(), 'written by the session, rendered [you] — never operator provenance');
        $hot = $this->memories->findNotesFor((int) $task->getId(), SessionMemoryTier::Hot);
        self::assertCount(1, $hot);
        self::assertSame('Migration 0007 adds the store; the importer consumes it.', $hot[0]->getText());

        // 4. The very next request carried them in the ## Memories block —
        //    the close of the loop: write → store → block → next request.
        $second = $chats[1][0];
        self::assertSame('assistant', $second[2]['role']);
        self::assertStringContainsString('## Memories', $second[2]['content']);
        self::assertStringContainsString('**Objective** — set by you: Finish the importer, then harden it.', $second[2]['content']);
        self::assertStringContainsString('- [you] Migration 0007 adds the store; the importer consumes it.', $second[2]['content']);
    }

    public function testAnOversizedWriteIsRefusedWithFeedbackAndPersistsNothing(): void
    {
        $task = $this->sessionTask();
        $run = $this->craftRun($task);

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return match (\count($chats)) {
                    1 => $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'session_note', 'arguments' => ['text' => str_repeat('x', 5000)]],
                    ]),
                    default => $this->response(toolCalls: [
                        ['id' => 'c2', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Learned the note was too long; declared anyway.']],
                    ]),
                };
            },
        );

        $this->bus()->dispatch(new SessionTurnMessage((int) $run->getId(), 1));
        $this->pump();

        $this->em->clear();
        $run = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $run->getStatus(), 'a refused write is feedback, not a run failure');

        // The refusal is recorded on the ledger (as `detail`, the same shape
        // an MCP-side failure takes) — and the feedback the model actually
        // received rides the next request's tool message, read below. (The
        // declaration that ended the slice is the other result.)
        $results = $this->toolResults($run);
        self::assertCount(2, $results);
        $refusal = $results[0];
        self::assertStringContainsString('TASKLOOM_SESSION_WRITE_MAX_CHARS', (string) $refusal['detail']);
        self::assertSame('harness', $refusal['origin']);

        $feedback = null;
        foreach ($chats[1] as $message) {
            if ('tool' === ($message['role'] ?? null) && 'c1' === ($message['tool_call_id'] ?? null)) {
                $feedback = (string) $message['content'];
            }
        }
        self::assertNotNull($feedback, 'the refusal was carried back to the model');
        self::assertStringContainsString('invalid_arguments', $feedback);
        self::assertStringContainsString('TASKLOOM_SESSION_WRITE_MAX_CHARS', $feedback);

        // And nothing persisted.
        self::assertSame([], $this->memories->findNotesFor((int) $task->getId()));
    }

    public function testARunKindTaskNeverSeesTheHarnessTools(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->runKindTask();
        $run = $this->craftRun($task);

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages, array $tools) use (&$chats): LlmResponse {
                $chats[] = [$messages, $tools];

                return $this->response(content: 'Plain completion.');
            },
        );

        $this->bus()->dispatch(new LlmTurnMessage((int) $run->getId(), 1));
        $this->pump();

        self::assertCount(1, $chats);
        $names = array_column(array_column($chats[0][1], 'function'), 'name');
        self::assertSame(['get_weather'], $names, 'the harness vocabulary is a session\'s alone');
        self::assertStringNotContainsString('session_note', (string) json_encode($chats[0][1]));
    }

    // --------------------------------------------------------------- helpers

    /**
     * The run a session's first slice creates — the same shape the slice
     * engine now creates for real (build order step 4 landed; this helper
     * stays because the tests drive hand-crafted starting states).
     */
    private function craftRun(Task $task): Run
    {
        $container = static::getContainer();
        $compiler = $container->get(PromptCompiler::class);
        $tools = $container->get(ToolboxResolver::class)->resolve($task);

        $run = new Run($task);
        $run->setToolboxSnapshot(ToolboxSnapshot::fromDefinitions($tools));
        $state = new LoopState(
            stepBudget: 50,
            toolRetries: 2,
            circuitBreakerThreshold: 3,
            promptHead: $compiler->compile($task, $tools),
        );
        $run->setCheckpoint($state->toCheckpoint());

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function sessionTask(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function runKindTask(): Task
    {
        $task = new Task('Ordinary task', 'Do the thing.', TaskKind::Run, ToolboxMode::Explicit, ['get_weather'], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /**
     * The tool_result events' payloads (tool, content, origin), in order.
     *
     * @return list<array<string, mixed>>
     */
    private function toolResults(Run $run): array
    {
        $results = [];
        foreach ($run->getEvents() as $event) {
            if (RunEventType::ToolResult === $event->getType()) {
                $results[] = $event->getPayload();
            }
        }

        return $results;
    }

    private function pump(int $maxDeliveries = 50): void
    {
        for ($i = 0; $i < $maxDeliveries; ++$i) {
            // Mirror the fleet: the LLM workers drain `llm` before `session`
            // (strict priority), the tools workers drain `tools`. Run-kind
            // and session-kind tests share this pump, so both kinds' lanes
            // are walked in the production order.
            $delivered = $this->deliverOne('llm');
            $delivered = $this->deliverOne('session') || $delivered;
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
