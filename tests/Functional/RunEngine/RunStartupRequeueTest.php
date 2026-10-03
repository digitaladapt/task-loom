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
use App\RunEngine\FleetId;
use App\RunEngine\FleetOwnership;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use App\RunEngine\RunTurnResult;
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

    /** The env as it was before a test set it; see commandAsFleetOwner()/tearDown(). */
    private string|false $restoreOwner = false;
    private string|false $restoreFleetId = false;
    private string|false $restoreGrabAfter = false;

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

        if (false === $this->restoreFleetId) {
            putenv(FleetId::ENV);
        } else {
            putenv(FleetId::ENV.'='.$this->restoreFleetId);
        }

        if (false === $this->restoreGrabAfter) {
            putenv(ClaimReaper::GRAB_AFTER_ENV);
        } else {
            putenv(ClaimReaper::GRAB_AFTER_ENV.'='.$this->restoreGrabAfter);
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
        self::assertStringContainsString('would be cleared (any age)', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'a dry run must not clear the claim');
        self::assertSame([], iterator_to_array($this->transport('llm')->get()), 'a dry run must not dispatch');

        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 (any age)', $tester->getDisplay());
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

    public function testASecondsOldClaimFromThisFleetIsStillReaped(): void
    {
        // THE REGRESSION TEST. A container `down`'d and `up`'d inside a minute
        // leaves claims seconds old, and the first cut of this feature decided
        // abandonment from claim *age* — so it swept nothing, while the
        // requeue it ran alongside happily dispatched turns that could never be
        // taken (claim() requires the same hour). Recovery that looked like
        // recovery and did nothing.
        //
        // The claim here is 5 seconds old, and the bound in force is the
        // default — any age. Age is the only question the sweep asks, and at
        // boot its answer is settled for every claim in the table: nothing in
        // this process group has reached the lane yet.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5, claimFleet: 'fleet-under-test');

        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 (any age)', $tester->getDisplay());
        self::assertNull($this->claimedAt($run), 'a claim left at boot is clearable at any age under the default bound');

        // And the run is now genuinely carryable: the delivery is *taken* and
        // runs, rather than dropped as Stale on the claim it could not win.
        // Asserting on claimedAt would prove nothing — a turn that runs to
        // completion releases its claim in the `finally`, so a null there is
        // consistent with both success and a no-op.
        $result = $this->engine->llmTurn((int) $run->getId(), 2);
        self::assertNotSame(
            RunTurnResult::Stale,
            $result,
            'the swept run must accept the delivery that previously bounced off the stale claim',
        );
        self::assertSame(RunTurnResult::Done, $result, 'the fixture\'s second answer completes the run');
    }

    public function testAFreshClaimSurvivesWhenTheGraceBoundIsRaised(): void
    {
        // THE MULTI-FLEET CASE, and the only situation in which the sweep may
        // decline a claim it can see. With more than one fleet against one
        // database, a claim found at boot may belong to a peer that is working
        // right now: "I saw it at boot" no longer proves "its owner is gone".
        // Raising the bound to longer than a turn can run restores the proof.
        //
        // Note what this test replaced. A previous version gave the claim an
        // id different from the fleet's and asserted it survived *for that
        // reason*. It passed — and it was worthless, because it passed for the
        // wrong reason and encoded the very bug that made the feature a no-op:
        // in production the two ids differ on every single restart.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5);

        $tester = $this->commandAsFleetOwner('1', grabAfter: 3600);
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 0 (older than 3600s)', $tester->getDisplay());
        self::assertStringContainsString('1 left to the lease', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'a claim fresher than the bound may be a live peer\'s, and must survive');
    }

    public function testTheGraceBoundStillClearsWhatIsOldEnough(): void
    {
        // The other half of the bound: it delays, it does not disable. A claim
        // past the bound is one no turn could still be holding.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 7200);

        $tester = $this->commandAsFleetOwner('1', grabAfter: 3600);
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 (older than 3600s)', $tester->getDisplay());
        self::assertNull($this->claimedAt($run));
    }

    public function testAClaimWithNoOwnerLabelIsStillClearedAndReported(): void
    {
        // A row written before the label existed, or by a process that is not
        // part of a supervised fleet — a one-shot `app:run:now` in a terminal.
        // The label is a diagnostic, not the decision: the claim is cleared on
        // age like any other, and its lack of a label is reported separately so
        // the operator can see what the label is and is not covering.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5, claimFleet: null);

        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 (any age)', $tester->getDisplay());
        self::assertStringContainsString('1 of those without an owner label', $tester->getDisplay());
        self::assertNull($this->claimedAt($run), 'the label is diagnostic; age is the decision');
    }

    public function testASweepWithNoFleetLabelStillWorksAndSaysSo(): void
    {
        // A manual `app:run:requeue --startup` from a terminal is a fleet owner
        // with no identity. It must still repair what it finds — that was the
        // whole complaint that led here — and it says out loud that the claims
        // it leaves behind will be unlabelled.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5);

        $tester = $this->commandAsFleetOwner('1', fleetId: null);
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString(FleetId::ENV, $tester->getDisplay());
        self::assertStringContainsString('will carry no owner label', $tester->getDisplay());
        self::assertStringContainsString('cleared 1 (any age)', $tester->getDisplay());
        self::assertNull($this->claimedAt($run), 'a fleet with no identity still sweeps — it just cannot label');
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
     *
     * $claimFleet is only a label — it makes a leftover claim legible in the
     * log and on the admin surface, and it is deliberately NOT what the sweep
     * decides on (see ClaimReaper for why an identity cannot express "me, from
     * last time"). The default stands for "claimed by the previous run of this
     * container", which is the case these tests are about.
     */
    private function runAwaitingItsSecondTurn(int $claimAgeSeconds = 7200, ?string $claimFleet = 'fleet-under-test'): Run
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

        // The claim the dead worker never released. Its age is what the sweep
        // decides on ("could this still be in flight?"); the fleet label is
        // kept because it is what makes the leftover legible afterwards.
        $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :at, claim_fleet = :fleet WHERE id = :id',
            ['at' => time() - $claimAgeSeconds, 'fleet' => $claimFleet, 'id' => $runId],
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
    private function commandAsFleetOwner(?string $value, ?string $fleetId = 'fleet-under-test', ?int $grabAfter = null): CommandTester
    {
        $this->restoreOwner = getenv(FleetOwnership::ENV);
        $this->restoreFleetId = getenv(FleetId::ENV);
        $this->restoreGrabAfter = getenv(ClaimReaper::GRAB_AFTER_ENV);

        if (null === $value) {
            putenv(FleetOwnership::ENV);
        } else {
            putenv(FleetOwnership::ENV.'='.$value);
        }

        if (null === $fleetId) {
            putenv(FleetId::ENV);
        } else {
            putenv(FleetId::ENV.'='.$fleetId);
        }

        // The grace bound: unset means the documented default (0 — take
        // anything, because at boot nothing can be in flight). Tests that model
        // a multi-fleet deployment set it explicitly.
        if (null === $grabAfter) {
            putenv(ClaimReaper::GRAB_AFTER_ENV);
        } else {
            putenv(ClaimReaper::GRAB_AFTER_ENV.'='.$grabAfter);
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
