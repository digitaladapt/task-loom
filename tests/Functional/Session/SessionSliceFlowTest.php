<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Command\RunRequeueCommand;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\RunTrigger;
use App\Entity\SessionDeclarationOutcome;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\SessionTurnMessage;
use App\Repository\RunRepository;
use App\RunEngine\LoopState;
use App\RunEngine\RunEngine;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolboxSnapshot;
use App\Session\SessionSliceEngine;
use App\Session\SessionState;
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
 * The slice engine end to end (docs/design/SESSION_TASKS.md §8, build order
 * step 4): a session's slices declare, settle, and succeed themselves —
 * `milestone` launches the next slice in the settlement commit, `done` ends
 * the session, `blocked` stops for a human, a slice that ends without a
 * declaration stops the session loudly, and the requeue sweep re-derives an
 * owed slice after a lost dispatch.
 *
 * The runs are driven through the **real lanes** (session → tools →
 * session with worker stamps) and through the real slice engine from the
 * container, so the settlement commit, the declaration record, the next
 * slice's carrier, and the derived session state are exercised exactly as
 * they run — on the `session` lane a slice's turns actually ride
 * (docs/design/SESSION_TASKS.md §7).
 *
 * The mock LLM speaks the protocol the prompt teaches: `session_declare`
 * alone in its round; anything else is a refusal the model must fix, which
 * one of the tests also proves.
 */
#[AllowMockObjectsWithoutExpectations]
final class SessionSliceFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)

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

        $container = static::getContainer();
        $this->engine = new RunEngine(
            $this->llm,
            $container->get(\App\RunEngine\PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $container->get(\App\RunEngine\ToolExecutorInterface::class),
            $container->get(\App\Context\ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(\App\RunEngine\RunGraph::class),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
            $this->bus(),
            $container->get(\App\Session\SessionMemoryRenderer::class),
            $container->get(\App\Session\SessionToolRunner::class),
            $container->get(SessionSliceEngine::class),
        );

        // Install BEFORE anything is dispatched: handlers are built lazily
        // on first delivery and resolve RunEngine through DI.
        $container->set(RunEngine::class, $this->engine);
    }

    /**
     * The loop the whole feature exists for: a slice works, declares a
     * milestone, and the NEXT slice is created — row and lane message — in
     * the same commit as the settlement; then that slice declares `done`
     * and the session rests, complete.
     */
    public function testAMilestoneSettlesTheSliceAndLaunchesTheNextOneInTheSameCommit(): void
    {
        $task = $this->sessionTask();
        $slices = static::getContainer()->get(SessionSliceEngine::class);

        // The operator's start: one slice, waiting for its first turn.
        $slice1 = $this->engine->start($task);
        self::assertSame(RunTrigger::Manual, $slice1->getTriggeredBy());

        // RunLauncher would carry the first turn; do the same by hand.
        $this->bus()->dispatch(new SessionTurnMessage((int) $slice1->getId(), 1));

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return 1 === \count($chats)
                    ? $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'session_declare', 'arguments' => ['outcome' => 'milestone', 'summary' => 'Importer skeleton is up.']],
                    ])
                    : $this->response(toolCalls: [
                        ['id' => 'c2', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Importer hardened; the session is complete.']],
                    ]);
            },
        );

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(2, $runs, 'the milestone launched exactly one successor');

        $first = $runs[0];
        self::assertSame(RunStatus::Succeeded, $first->getStatus());
        self::assertSame(SessionDeclarationOutcome::Milestone, $first->getDeclarationOutcome());
        self::assertSame('Importer skeleton is up.', $first->getDeclaration()['summary'] ?? null);

        // The milestone's summary is the slice's artifact: the Completion
        // event carries it, so the ledger and the digest read it like any
        // other run's result (SPEC §5.4's shape, one level down).
        self::assertSame('Importer skeleton is up.', $this->completionResultOf($first));

        $second = $runs[1];
        self::assertSame(RunStatus::Succeeded, $second->getStatus());
        self::assertSame(SessionDeclarationOutcome::Done, $second->getDeclarationOutcome());
        self::assertSame(RunTrigger::Continuation, $second->getTriggeredBy(), 'the next slice records WHY it happened');
        self::assertSame($task->getId(), $second->getTask()->getId());

        // The frozen constitution carries the slice budget, not the run one.
        $checkpoint = $second->getCheckpoint();
        self::assertSame(25, $checkpoint['stepBudget'] ?? null, 'a slice freezes the slice budget');

        // Done is terminal: nothing follows it.
        self::assertSame(SessionState::Complete, $slices->state($task));
        self::assertCount(0, $this->lane('session'), 'no carrier is left behind');
    }

    /**
     * The lane the slice's turns ride is the `session` lane
     * (docs/design/SESSION_TASKS.md §7): the engine builds
     * `SessionTurnMessage` — and never `LlmTurnMessage` — for a session-kind
     * run, so chat > task > session is a property of the configuration, not
     * of every dispatch site remembering to pick the right class.
     */
    /**
     * A slice the OPERATOR starts carries the harness tools on the wire
     * (SPEC §4.1's harness-tool clause). The other wire tests craft their
     * runs by hand; this one goes through the real path — `start()` creates
     * the first slice exactly as Run now does, and the first request the
     * model sees must offer `session_note`, `session_objective` and
     * `session_declare` as tool definitions, not only in the prompt's
     * toolbox prose.
     */
    public function testAnOperatorStartedSlicesFirstRequestOffersTheHarnessTools(): void
    {
        $this->catalogTool('get_weather');
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, ['get_weather'], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        $slice = $this->engine->start($task);
        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages, array $tools) use (&$chats): LlmResponse {
                $chats[] = [$messages, $tools];

                return $this->response(toolCalls: [
                    ['id' => 'c1', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Nothing to do yet.']],
                ]);
            },
        );

        $this->pump();

        self::assertCount(1, $chats, 'the slice made exactly one request before declaring');
        $names = array_column(array_column($chats[0][1], 'function'), 'name');

        self::assertContains('get_weather', $names, 'the declared catalog tool rides the wire');
        self::assertContains('session_note', $names);
        self::assertContains('session_objective', $names);
        self::assertContains('session_declare', $names);

        // And the frozen snapshot — what every later turn and worker reads —
        // records the same tools, harness entries marked as such.
        $this->em->clear();
        $frozen = $this->runs()[0]->getToolboxSnapshot();
        $origins = [];
        foreach ($frozen as $entry) {
            $origins[$entry['tool']] = $entry['origin'] ?? 'mcp';
        }
        self::assertSame('mcp', $origins['get_weather']);
        self::assertSame('harness', $origins['session_note']);
        self::assertSame('harness', $origins['session_objective']);
        self::assertSame('harness', $origins['session_declare']);
    }

    public function testASlicesTurnsAreBuiltForTheSessionLane(): void
    {
        $task = $this->sessionTask();
        $slice = $this->engine->start($task);

        $message = $this->engine->nextTurnMessage($slice);
        self::assertInstanceOf(SessionTurnMessage::class, $message, 'a slice\'s LLM turn is a SessionTurnMessage');
        self::assertSame(1, $message->step);

        // And an ordinary task's run is untouched: same call, the other lane.
        $this->catalogTool('get_weather');
        $runTask = new Task('Ordinary', 'Do it.', TaskKind::Run, ToolboxMode::Explicit, ['get_weather'], TaskAuthor::User);
        $runTask->enable();
        $this->em->persist($runTask);
        $this->em->flush();

        $ordinary = $this->engine->start($runTask);
        self::assertInstanceOf(\App\Message\LlmTurnMessage::class, $this->engine->nextTurnMessage($ordinary));
    }

    /**
     * `done` ends the session: the slice settles succeeded and no
     * successor is owed (the observed state is `complete`).
     */
    public function testADoneDeclarationEndsTheSession(): void
    {
        $task = $this->sessionTask();
        $slice = $this->engine->start($task);
        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        $this->llm->method('chat')->willReturn($this->response(toolCalls: [
            ['id' => 'c1', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'All done.']],
        ]));

        $this->pump();

        $this->em->clear();
        self::assertCount(1, $this->runs());

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Complete, $slices->state($this->sessionTaskRefetched()));

        // And a start on a complete session is refused, loudly (a new
        // session — or an approved replacement — is how work continues).
        try {
            $this->engine->start($this->sessionTaskRefetched());
            self::fail('Expected a refusal for a complete session.');
        } catch (\App\Session\SessionConstraintException $e) {
            self::assertStringContainsString('complete', $e->getMessage());
        }
    }

    /**
     * `blocked` is a designed stop, not a fault: the slice settles
     * needs_attention with NO error class, the ledger says why, no
     * successor launches, and the derived state is `blocked`.
     */
    public function testABlockedDeclarationStopsForAHuman(): void
    {
        $task = $this->sessionTask();
        $slice = $this->engine->start($task);
        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        $this->llm->method('chat')->willReturn($this->response(toolCalls: [
            ['id' => 'c1', 'name' => 'session_declare', 'arguments' => ['outcome' => 'blocked', 'summary' => 'Need the API key before proceeding.']],
        ]));

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(1, $runs, 'a blocked slice launches nothing');

        $settled = $runs[0];
        self::assertSame(RunStatus::NeedsAttention, $settled->getStatus());
        self::assertNull($settled->getErrorClass(), 'a blocked declaration is not an error class — nothing broke');
        self::assertSame(SessionDeclarationOutcome::Blocked, $settled->getDeclarationOutcome());

        $reason = null;
        foreach ($settled->getEvents() as $event) {
            if (RunEventType::Failure === $event->getType()) {
                $reason = $event->getPayload()['reason'] ?? null;
            }
        }
        self::assertIsString($reason);
        self::assertStringContainsString('declared blocked: Need the API key before proceeding.', $reason);

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Blocked, $slices->state($this->sessionTaskRefetched()));

        // The operator's resume: Run now launches the next slice — the
        // blocked session is owed work again once a human says so.
        $revived = $this->engine->start($this->sessionTaskRefetched());
        self::assertSame(RunTrigger::Manual, $revived->getTriggeredBy());
        self::assertSame(SessionState::Working, $slices->state($this->sessionTaskRefetched()));
    }

    /**
     * A slice that ends WITHOUT a declaration stops the session for a human
     * (§8): no completion artifact, a loud ledger line, and — the part that
     * matters most — NO automatic retry. The derived state is `stopped`.
     */
    public function testASliceThatEndsWithoutDeclaringStopsTheSession(): void
    {
        $task = $this->sessionTask();
        $slice = $this->engine->start($task);
        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        // A plain terminal message — prose, no declaration. (A session is
        // told not to do this, but the engine must hold the line if it
        // happens anyway.)
        $this->llm->method('chat')->willReturn($this->response(content: 'I think that is probably enough for now!'));

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(1, $runs, 'no retry, no successor — a human looks');

        $settled = $runs[0];
        self::assertSame(RunStatus::Incomplete, $settled->getStatus());
        self::assertNull($settled->getDeclarationOutcome());

        $reason = null;
        foreach ($settled->getEvents() as $event) {
            if (RunEventType::Failure === $event->getType()) {
                $reason = $event->getPayload()['reason'] ?? null;
            }
        }
        self::assertIsString($reason);
        self::assertStringContainsString('no session_declare', $reason);

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Stopped, $slices->state($this->sessionTaskRefetched()));

        // And the operator can resume it — one action, no drama.
        $revived = $this->engine->start($this->sessionTaskRefetched());
        self::assertSame(RunTrigger::Manual, $revived->getTriggeredBy());
    }

    /**
     * A declaration is alone in its round (§5): a round carrying
     * `session_declare` beside other calls is refused whole — nothing
     * dispatches, nothing persists from it — and the refusal is fed back,
     * so the model re-issues it cleanly (which this test then does).
     */
    public function testADeclarationBesideOtherCallsIsRefusedWholeAndFedBack(): void
    {
        $task = $this->sessionTask();
        $slice = $this->engine->start($task);
        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                return 1 === \count($chats)
                    ? $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'session_note', 'arguments' => ['text' => 'This note must not land.']],
                        ['id' => 'c2', 'name' => 'session_declare', 'arguments' => ['outcome' => 'milestone', 'summary' => 'With company — refused.']],
                    ])
                    : $this->response(toolCalls: [
                        ['id' => 'c3', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Declared alone, as required.']],
                    ]);
            },
        );

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(1, $runs);
        $settled = $runs[0];
        self::assertSame(RunStatus::Succeeded, $settled->getStatus());
        self::assertSame(SessionDeclarationOutcome::Done, $settled->getDeclarationOutcome());

        // Nothing from the refused round dispatched: the note never landed,
        // and the declaration the model first attempted was never recorded.
        self::assertSame([], static::getContainer()->get(\App\Repository\SessionMemoryRepository::class)->findNotesFor((int) $task->getId()));

        // The refusal the model saw, on the next request: both calls came
        // back as invalid_arguments naming the alone rule.
        $feedback = [];
        foreach ($chats[1] as $message) {
            if ('tool' === ($message['role'] ?? null)) {
                $feedback[(string) ($message['tool_call_id'] ?? '')] = (string) $message['content'];
            }
        }
        self::assertArrayHasKey('c1', $feedback);
        self::assertArrayHasKey('c2', $feedback);
        self::assertStringContainsString('alone', $feedback['c1']);
        self::assertStringContainsString('alone', $feedback['c2']);
    }

    /**
     * The requeue sweep's session pass (§8): an enabled session whose last
     * slice declared a milestone but whose successor never committed (the
     * lost-dispatch shape) is re-derived and launched — and a healthy
     * session reconciles to nothing.
     */
    public function testTheRequeueSweepLaunchesAnOwedSliceAfterALostDispatch(): void
    {
        $task = $this->sessionTask();
        $slices = static::getContainer()->get(SessionSliceEngine::class);

        // The lost-dispatch shape: a terminal slice that declared a
        // milestone, with no successor row — exactly what a crash between
        // "settled" and "committed the successor" would leave (and what the
        // sweep exists to repair).
        $settled = $this->craftSlice($task, RunTrigger::Manual);
        $settled->recordDeclaration(SessionDeclarationOutcome::Milestone, 'Halfway.');
        $settled->markSucceeded();
        $this->em->persist($settled);
        $this->em->flush();

        self::assertSame(SessionState::Owed, $slices->state($this->taskRefetch($task)));

        $tester = new CommandTester(static::getContainer()->get(RunRequeueCommand::class));

        // Dry run first: reports, launches nothing.
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('would launch the next slice', $tester->getDisplay());
        self::assertCount(1, $this->runs(), 'dry run must create nothing');

        $tester->execute([]);
        self::assertStringContainsString('launched the next slice', $tester->getDisplay());

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(2, $runs);

        $successor = $runs[1];
        self::assertSame(RunTrigger::Continuation, $successor->getTriggeredBy());
        self::assertSame(RunStatus::Queued, $successor->getStatus(), 'waiting on the lanes is the committed state');
        self::assertCount(1, $this->lane('session'), 'its first turn is on the session lane, committed with the row');
        self::assertCount(0, $this->lane('llm'), 'a session never competes with a task for the next worker');
        self::assertSame(SessionState::Working, $slices->state($this->taskRefetch($task)));

        // Converged: a second sweep finds nothing to do.
        $tester->execute(['--dry-run' => true]);
        self::assertStringNotContainsString('would launch', $tester->getDisplay());
    }

    /**
     * The shape gates that outlived the blanket kind-gate, at the lifecycle
     * service (docs/design/SESSION_TASKS.md §8): a session cannot be
     * scheduled, and enable refuses one that is — the out-of-band row must
     * not become a cron-launched session.
     */
    public function testAScheduledSessionIsRefusedAtEnableAndAtDispatch(): void
    {
        $task = new Task('Long haul', 'Keep going.', TaskKind::Session, ToolboxMode::Tags, [], TaskAuthor::User);
        $task->setSchedule('0 9 * * *');
        $this->em->persist($task);
        $this->em->flush();

        $admin = static::getContainer()->get(\App\Admin\TaskAdminService::class);

        try {
            $admin->enableTask((int) $task->getId());
            self::fail('Expected the lifecycle gate to refuse a scheduled session.');
        } catch (\App\Admin\TaskLifecycleException $e) {
            self::assertStringContainsString('cannot be scheduled', $e->getMessage());
        }

        // And even an out-of-band enable cannot get a scheduled session
        // sliced: the slice engine refuses the schedule at creation, so the
        // scheduler's door fails it loudly instead of cron-launching it.
        $this->em->getConnection()->executeStatement('UPDATE task SET enabled = 1 WHERE id = ?', [$task->getId()]);
        $this->em->clear();

        $slices = static::getContainer()->get(SessionSliceEngine::class);

        try {
            $slices->start($this->taskRefetch($task));
            self::fail('Expected the slice engine to refuse a scheduled session.');
        } catch (\App\Session\SessionConstraintException $e) {
            self::assertStringContainsString('cannot be scheduled', $e->getMessage());
        }
    }

    // --------------------------------------------------------------- helpers

    private function sessionTask(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /** One catalog tool, so an ordinary run-kind task's toolbox resolves. */
    private function catalogTool(string $name): void
    {
        $server = new \App\Entity\McpServer('test-server', 'https://server.example/mcp', \App\Entity\ServerProtocol::Mcp);
        $this->em->persist($server);

        $tool = new \App\Entity\Tool($server, $name, 'test tool', ['type' => 'object'], []);
        $this->em->persist($tool);
        $this->em->flush();
    }

    private function sessionTaskRefetched(): Task
    {
        $task = $this->em->getRepository(Task::class)->findOneBy([]);
        \assert($task instanceof Task);

        return $task;
    }

    private function taskRefetch(Task $task): Task
    {
        $fresh = $this->em->getRepository(Task::class)->find($task->getId());
        \assert($fresh instanceof Task);

        return $fresh;
    }

    /**
     * A slice run crafted exactly as SessionSliceEngine::createSlice()
     * crafts one — for tests that need a committed starting state the
     * engine itself would not produce (a lost-dispatch shape).
     */
    private function craftSlice(Task $task, RunTrigger $trigger): Run
    {
        $container = static::getContainer();
        $tools = $container->get(ToolboxResolver::class)->resolve($task);

        $run = new Run($task);
        $run->setTriggeredBy($trigger);
        $run->setToolboxSnapshot(ToolboxSnapshot::fromDefinitions($tools));
        $run->setCheckpoint((new LoopState(
            stepBudget: 25,
            toolRetries: 2,
            circuitBreakerThreshold: 3,
            promptHead: $container->get(\App\RunEngine\PromptCompiler::class)->compileForSlice($task, $tools),
        ))->toCheckpoint());

        return $run;
    }

    private function completionResultOf(Run $run): ?string
    {
        foreach ($run->getEvents() as $event) {
            if (RunEventType::Completion === $event->getType()) {
                $result = $event->getPayload()['result'] ?? null;

                return \is_string($result) ? $result : null;
            }
        }

        return null;
    }

    private function pump(int $maxDeliveries = 50): void
    {
        for ($i = 0; $i < $maxDeliveries; ++$i) {
            $delivered = $this->deliverOne('session');
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

    /**
     * The envelopes sitting on a lane, as their messages.
     *
     * @return list<object>
     */
    private function lane(string $lane): array
    {
        // fetchSize high on purpose: the default (1) would let a second
        // envelope hide, and "exactly one carrier" is the assertion.
        $messages = [];
        foreach ($this->transport($lane)->get(1000) as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
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
        return $this->em->createQuery('SELECT r FROM App\Entity\Run r ORDER BY r.id ASC')->getResult();
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
