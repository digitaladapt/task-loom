<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Command\RunRequeueCommand;
use App\Context\ContextWindow;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\ServerProtocol;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Message\LlmTurnMessage;
use App\Repository\RunRepository;
use App\RunEngine\ClaimReaper;
use App\RunEngine\FleetOwnership;
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
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Boot recovery, end to end (SPEC §6.2): the claim reap, the fleet-owner gate,
 * and the dispatch both halves depend on.
 *
 * The scenario these tests build is the one the design note is about: a fleet
 * killed rather than stopped. The run's state is committed and correct, a
 * claim is left behind by a process that will never release it, and the
 * successor message is gone with the process that owed it.
 */
#[AllowMockObjectsWithoutExpectations]
final class RunStartupRequeueTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)

    /** The fleet-owner flag as it was before a test set it; see commandAsFleetOwner(). */
    private string|false $restoreOwner = false;

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
        );

        $container->set(RunEngine::class, $this->engine);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Leave the process environment as it was found: an inherited
        // TASKLOOM_FLEET_OWNER would otherwise make the "not an owner" tests
        // pass or fail depending on what ran before them.
        if (false === $this->restoreOwner) {
            putenv(FleetOwnership::ENV);
        } else {
            putenv(FleetOwnership::ENV.'='.$this->restoreOwner);
        }

        parent::tearDown();
    }

    public function testStartupSweepClearsAStaleClaimAndRequeueDispatchesTheOwedTurn(): void
    {
        $run = $this->runAwaitingItsSecondTurn();

        $tester = $this->commandAsFleetOwner('1');

        // Dry run reports without changing anything: the claim is still held
        // and nothing has been dispatched.
        $tester->execute(['--startup' => true, '--dry-run' => true]);
        self::assertStringContainsString('would be cleared as abandoned', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'a dry run must not clear the claim');
        self::assertSame([], iterator_to_array($this->transport('llm')->get()), 'a dry run must not dispatch');

        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 abandoned', $tester->getDisplay());
        self::assertNull($this->claimedAt($run), 'the abandoned claim is cleared, so the run is immediately carryable');

        // The reap only *enabled* the recovery; the requeue is what fed the run.
        $owed = iterator_to_array($this->transport('llm')->get());
        self::assertCount(1, $owed);
        self::assertInstanceOf(LlmTurnMessage::class, $owed[array_key_first($owed)]->getMessage());
    }

    public function testAProcessThatDoesNotOwnTheFleetSweepsNothing(): void
    {
        $run = $this->runAwaitingItsSecondTurn();

        // The compose `migrate` service, an ad-hoc console command, a UI-only
        // deployment: all of these boot without starting a worker fleet, and
        // none of them may clear another process's claim.
        $tester = $this->commandAsFleetOwner(null);
        $tester->execute(['--startup' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Not the fleet owner', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'a non-owner must leave the claim alone');
        self::assertSame([], iterator_to_array($this->transport('llm')->get()), 'a non-owner must dispatch nothing');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unrecognizedFlags(): iterable
    {
        yield 'the word someone writes when they mean yes' => ['yes'];
        yield 'a typo' => ['ture'];
        yield 'a stray path' => ['/run/taskloom'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrecognizedFlags')]
    public function testAnUnrecognizedFlagFailsClosedAndSaysSo(string $value): void
    {
        $run = $this->runAwaitingItsSecondTurn();

        $tester = $this->commandAsFleetOwner($value);
        $tester->execute(['--startup' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('not a recognized value', $tester->getDisplay());
        self::assertStringContainsString('TASKLOOM_FLEET_OWNER', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'an unrecognized flag must not grant authority');
    }

    public function testAFreshClaimIsLeftAloneEvenByTheFleetOwner(): void
    {
        // The sweep reaps what the engine would already consider abandoned,
        // and nothing fresher. This is the property that keeps the boot path
        // from being a special case: "abandoned" has exactly one definition
        // (CLAIM_STALE_SECONDS), so a live worker's claim is as safe from the
        // boot sweep as it is from an ordinary takeover.
        //
        // Note what this test does NOT claim. A fresh claim survives, so the
        // run is left claimed. But requeue does not skip a *deliverable* turn
        // because a claim is held — that would mean waking every worker to ask
        // whether a run is being handled, on every sweep. It dispatches, and
        // the claim adjudicates on delivery: the worker that holds the live
        // claim finishes the turn, and the duplicate drops (asserted in
        // RunEngineAsyncFlowTest). The claim is the mutex, not the router.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5);

        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 0 abandoned', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'a live-looking claim must survive the boot sweep');

        // At most one delivery for the run, and it is the turn the run owes —
        // never a second, never a tool turn the state does not call for.
        $owed = iterator_to_array($this->transport('llm')->get());
        self::assertLessThanOrEqual(1, \count($owed));
        foreach ($owed as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(LlmTurnMessage::class, $message);
            self::assertSame($run->getId(), $message->runId);
        }
    }

    public function testStartupCannotBeScopedToOneRun(): void
    {
        // The gate is about who may reap, not which run; a per-run scope would
        // make "which claim is abandoned?" a judgement call, which is exactly
        // what the fleet-owner rule exists to avoid.
        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true, 'run-id' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('cannot be scoped to a single run', $tester->getDisplay());
    }

    public function testPlainRequeueStillWorksWithoutTheStartupFlag(): void
    {
        // The operator's recovery command is unchanged and needs no flag, no
        // fleet-ownership, and no env var: only the *reap* half is gated.
        $this->runAwaitingItsSecondTurn();

        $tester = $this->commandAsFleetOwner(null);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('would be requeued', $tester->getDisplay());
        self::assertStringNotContainsString('Not the fleet owner', $tester->getDisplay());
    }

    // --------------------------------------------------------------- helpers

    /**
     * A run that has committed work, is owed its next turn, and is claimed by
     * a worker that (in the fiction of the test) then died: the successor
     * message is gone and the claim was never released.
     *
     * Built by driving the real engine, so the committed state is production
     * state rather than a hand-assembled fixture — including the detail that
     * matters here: the owed *LLM* turn exists only because the tool turn
     * before it committed and then its successor's carrier was lost, which is
     * the shape a process killed mid-flight leaves behind.
     *
     * The last LLM turn is deliberately never delivered. A turn that runs to
     * completion with no tool calls ends the run, and a terminal run owes
     * nothing — the fixture would be asserting against the wrong state.
     */
    private function runAwaitingItsSecondTurn(int $claimAgeSeconds = 7200): Run
    {
        $this->catalogTool('get_weather');

        /** @var list<LlmResponse> $answers */
        $answers = [
            $this->response(toolCalls: [['id' => 'c1', 'name' => 'get_weather', 'arguments' => ['location' => 'Reykjavik']]]),
            $this->response(content: 'Done.'),
        ];
        $this->llm->method('chat')->willReturnCallback(
            function () use (&$answers): LlmResponse {
                return array_shift($answers) ?? $this->response(content: 'Done.');
            },
        );
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturn(['tool' => 'get_weather', 'content' => 'sunny', 'isError' => false, 'durationMs' => 1]);

        $run = $this->engine->start($this->enabledTask());
        $runId = (int) $run->getId();

        // Turn 1 commits the pending tool turn (and its lane message).
        $this->bus()->dispatch(new LlmTurnMessage($runId, 1));
        $this->deliverOne('llm');

        // The tool turn runs and commits turn 2's message — then that carrier
        // is lost with the process that was about to consume it.
        $this->deliverOne('tools');
        $this->transport('llm')->reset();

        // The claim the dead worker never released.
        $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :at WHERE id = :id',
            ['at' => time() - $claimAgeSeconds, 'id' => $runId],
        );

        $this->em->clear();

        return $this->em->getRepository(Run::class)->find($runId) ?? self::fail('the run vanished');
    }

    /**
     * The command as it is actually constructed in the container — no
     * constructor seam for the gate.
     *
     * That is deliberate. The flag reaches the command through the process
     * environment (FleetOwnership::fromProcessEnv), the same way it reaches it
     * in a container, so these tests exercise the real mechanism rather than a
     * test-only injection point. The value is restored afterwards so a leaked
     * TASKLOOM_FLEET_OWNER cannot silently grant authority to a later test.
     */
    private function commandAsFleetOwner(?string $value): CommandTester
    {
        $this->restoreOwner = getenv(FleetOwnership::ENV);

        if (null === $value) {
            putenv(FleetOwnership::ENV);
        } else {
            putenv(FleetOwnership::ENV.'='.$value);
        }

        $container = static::getContainer();

        return new CommandTester(new RunRequeueCommand(
            $container->get(RunRepository::class),
            $this->engine,
            $this->bus(),
            $container->get(RunGraph::class),
            $container->get(ClaimReaper::class),
            new NullLogger(),
        ));
    }

    private function claimedAt(Run $run): ?int
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT claimed_at FROM run WHERE id = :id',
            ['id' => $run->getId()],
        );

        return \is_numeric($value) ? (int) $value : null;
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
        // The toolbox is selected by server tag, matching catalogTool()'s
        // fixture above — the engine resolves it through the real resolver.
        $task = new Task(
            title: 'Startup sweep',
            brief: 'Do the sweep thing.',
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

    private function bus(): MessageBusInterface
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Deliver one message on a lane with the stamps a real worker attaches —
     * the handler's ConsumedByWorkerStamp check is part of the behaviour under
     * test, so a bare dispatch would exercise a different path.
     */
    private function deliverOne(string $lane): void
    {
        $envelopes = iterator_to_array($this->transport($lane)->get());
        self::assertNotEmpty($envelopes, 'nothing to deliver on the '.$lane.' lane');

        $envelope = $envelopes[array_key_first($envelopes)];
        $this->bus()->dispatch($envelope->with(new ReceivedStamp($lane), new ConsumedByWorkerStamp()));
        $this->transport($lane)->ack($envelope);
    }
}
