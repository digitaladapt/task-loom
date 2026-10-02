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

    /** The env as it was before a test set it; see setUpFleetEnv()/tearDown(). */
    private string|false $restoreOwner = false;
    private string|false $restoreFleetId = false;

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

        parent::tearDown();
    }

    public function testStartupSweepClearsAStaleClaimAndRequeueDispatchesTheOwedTurn(): void
    {
        $run = $this->runAwaitingItsSecondTurn();

        $tester = $this->commandAsFleetOwner('1');

        // Dry run reports without changing anything: the claim is still held
        // and nothing has been dispatched.
        $tester->execute(['--startup' => true, '--dry-run' => true]);
        self::assertStringContainsString('would be cleared (left by this fleet)', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'a dry run must not clear the claim');
        self::assertSame([], iterator_to_array($this->transport('llm')->get()), 'a dry run must not dispatch');

        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 (left by this fleet)', $tester->getDisplay());
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
        // The claim here is 5 seconds old. Its owner is this fleet. That is a
        // dead predecessor by identity, whatever the clock says, so it goes.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5, claimFleet: 'fleet-under-test');

        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('cleared 1 (left by this fleet)', $tester->getDisplay());
        self::assertNull($this->claimedAt($run), 'a dead predecessor\'s claim is clearable at any age');

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

    public function testAnotherFleetsClaimIsLeftToTheLease(): void
    {
        // A workers-only host beside a UI, sharing one database: the other
        // fleet may be running this very second. Its claim is not abandoned,
        // however old it looks, and the sweep must not touch it.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 7200, claimFleet: 'some-other-fleet');

        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('0 (left by this fleet), 1 held by another fleet', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'another fleet\'s claim must survive the boot sweep');
    }

    public function testAClaimWithNoRecordedOwnerIsLeftToTheLease(): void
    {
        // A legacy row, or a turn taken by a process with no fleet identity —
        // a one-shot app:run:now in a terminal, which is very much alive.
        // Nothing is proven about it, so nothing is done to it: it waits on
        // CLAIM_STALE_SECONDS, slowly and safely. This is why claim_fleet is
        // nullable rather than defaulted.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5, claimFleet: null);

        $tester = $this->commandAsFleetOwner('1');
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString('0 (left by this fleet), 0 held by another fleet, 1 with no owner recorded', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'an unattributable claim must survive the boot sweep');
    }

    public function testAFleetOwnerWithNoFleetIdRefusesToSweep(): void
    {
        // The belt to the gate's braces: even a process that *says* it owns
        // the fleet cannot sweep if it has no identity to attribute claims to.
        // Sweeping zero silently is exactly how the first cut looked like it
        // worked, so this says so out loud.
        $run = $this->runAwaitingItsSecondTurn(claimAgeSeconds: 5);

        $tester = $this->commandAsFleetOwner('1', fleetId: null);
        $tester->execute(['--startup' => true]);

        self::assertStringContainsString(FleetId::ENV, $tester->getDisplay());
        self::assertStringContainsString('no claim can be attributed', $tester->getDisplay());
        self::assertNotNull($this->claimedAt($run), 'no fleet id means no attribution, and so no clearing');
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
     * $claimFleet is what makes the claim attributable. The default is the
     * fleet these tests run as, i.e. a claim this fleet left behind — the
     * "killed and restarted" case. Pass another id for a claim held by a
     * different fleet, or null for one nobody can account for.
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

        // The claim the dead worker never released — stamped with the fleet
        // that held it, which is the fact the sweep acts on.
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
    private function commandAsFleetOwner(?string $value, ?string $fleetId = 'fleet-under-test'): CommandTester
    {
        $this->restoreOwner = getenv(FleetOwnership::ENV);
        $this->restoreFleetId = getenv(FleetId::ENV);

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
