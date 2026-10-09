<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Context\ContextWindow;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Entity\ServerProtocol;
use App\Entity\SessionMemorySource;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\LlmTurnMessage;
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
 * The `## Memories` seam end to end (docs/design/SESSION_TASKS.md §4.1,
 * build order step 2): a session's block rides **every** request of the run
 * — after the frozen head, before the kept exchanges — and an operator edit
 * lands on the very next request, with no restart and no slice boundary.
 *
 * This is the step's "provable against a single long run", and the shape of
 * the proof matters. The step-1 dispatch gate still (correctly) refuses
 * session-kind tasks at run()/start() until the slice engine lands — so the
 * run is crafted exactly as the slice launcher will craft it, and then
 * driven **through the turn cores via the real lanes**: the first message is
 * dispatched to the in-memory `llm` lane and delivered with the same stamps
 * a worker attaches, after which the run walks `llm → tools → llm` on its
 * own committed state. Nothing calls a private seam.
 */
#[AllowMockObjectsWithoutExpectations]
final class SessionMemoryInjectionTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryStore $store; // @phpstan-ignore property.uninitialized (assigned in setUp)

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
        );

        // Install BEFORE anything is dispatched: handlers are built lazily
        // on first delivery and resolve RunEngine through DI.
        $container->set(RunEngine::class, $this->engine);

        $this->store = new SessionMemoryStore($this->em, $container->get(SessionMemoryRepository::class), hotCap: 5, coldCap: 25, writeMaxChars: 2000);
    }

    public function testTheBlockRidesEveryRequestAndAnOperatorEditLandsOnTheNextRequest(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->sessionTask();
        $this->store->setObjective($task, 'Steer: the importer only.', SessionMemorySource::Operator);
        $this->store->addNote($task, 'Migration 0007 adds the store.', SessionMemorySource::Session);

        $run = $this->craftRun($task);
        $this->wireToolRoundTrip();

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return 1 === \count($chats)
                    ? $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]])
                    : $this->response(content: 'Done, warmed up.');
            },
        );

        // The first turn, delivered exactly as a worker would.
        $this->bus()->dispatch(new LlmTurnMessage((int) $run->getId(), 1));
        $this->deliverOne('llm');

        self::assertCount(1, $chats);
        $first = $chats[0];
        self::assertCount(3, $first, 'head + memory, nothing else on the first request');
        self::assertSame('system', $first[0]['role']);
        self::assertSame('user', $first[1]['role']);
        self::assertSame('assistant', $first[2]['role'], 'the block rides on the assistant role');
        self::assertStringContainsString('## Memories', $first[2]['content']);
        self::assertStringContainsString('**Objective** — set by the operator: Steer: the importer only.', $first[2]['content']);
        self::assertStringContainsString('- [you] Migration 0007 adds the store.', $first[2]['content']);

        // The operator steers now — mid-run, between requests. No restart,
        // no slice boundary: the next request must carry the edit (§4.1).
        $this->store->setObjective($task, 'Steer: the importer and the parser.', SessionMemorySource::Operator);
        $this->store->addNote($task, 'Operator: keep PRs small.', SessionMemorySource::Operator);

        $this->pump();

        $this->em->clear();
        $run = $this->runs()[0];
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertCount(2, $chats, 'the run made two requests, one per turn');

        $second = $chats[1];
        self::assertSame('assistant', $second[2]['role']);
        self::assertStringContainsString('**Objective** — set by the operator: Steer: the importer and the parser.', $second[2]['content'], 'the edit lands on the very next request');
        self::assertStringContainsString('- [operator] Operator: keep PRs small.', $second[2]['content']);
        self::assertStringContainsString('- [you] Migration 0007 adds the store.', $second[2]['content'], 'the carried note is still there');
        self::assertStringNotContainsString('Steer: the importer only.', $second[2]['content'], 'the superseded steer is replaced, not accumulated');
        self::assertSame('c1', $second[3]['tool_calls'][0]['id'], 'the kept exchange still follows the block, whole');
        self::assertSame('c1', $second[4]['tool_call_id']);
    }

    public function testAnOrdinaryRunNeverCarriesTheBlock(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->runKindTask();
        $this->wireToolRoundTrip();

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return 1 === \count($chats)
                    ? $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]])
                    : $this->response(content: 'Done.');
            },
        );

        $run = $this->engine->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertCount(2, $chats);
        foreach ($chats as $messages) {
            self::assertStringNotContainsString('## Memories', (string) json_encode($messages), 'memory is a session\'s, attributed to the session alone');
        }
    }

    // --------------------------------------------------------------- helpers

    private function wireToolRoundTrip(): void
    {
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 5]);
    }

    /**
     * The run a session's first slice will create, crafted exactly as
     * RunEngine::begin() crafts a standalone run — because run()/start()
     * still refuse the kind until the slice engine lands (step 4).
     */
    private function craftRun(Task $task): Run
    {
        $container = static::getContainer();
        $compiler = $container->get(PromptCompiler::class);
        $tools = $container->get(ToolboxResolver::class)->resolve($task);

        $run = new Run($task);
        $run->setToolboxSnapshot(ToolboxSnapshot::fromTools($tools));
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
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, ['get_weather'], TaskAuthor::User);
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
     * Drive every lane until it settles — what `messenger:consume llm tools`
     * does in production, one message at a time, with the stamps a worker
     * attaches. Mirrors RunEngineAsyncFlowTest, deliberately: the lanes here
     * ARE the production routing.
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
