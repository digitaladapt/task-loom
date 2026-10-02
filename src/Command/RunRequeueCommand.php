<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Run;
use App\Entity\RunRole;
use App\Repository\RunRepository;
use App\RunEngine\ClaimReaper;
use App\RunEngine\FleetId;
use App\RunEngine\FleetOwnership;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Recovery: re-dispatch the work active runs are owed (SPEC §6, §13.3).
 *
 * The transport redelivers messages whose worker died, and duplicate
 * deliveries are adjudicated by the execution claim — but a message lost in
 * ways redelivery cannot see (queue table purged, database restored from a
 * backup) leaves a run with committed state and no carrier. The run row is
 * the durable queue of record; this command re-derives what is owed from it
 * and dispatches.
 *
 * Three sweeps, in order (§6.2, §13.3):
 *
 * 1. **Claim reap** (`--startup` only) — clear execution claims abandoned by
 *    a fleet that is no longer running, so the work below is not held back by
 *    a lease whose owner is known to be gone. See ClaimReaper, and
 *    FleetOwnership for why this runs only in the process group that owns the
 *    fleet.
 * 2. **Graph reconcile** — for every active parent run, re-derive what the
 *    committed graph owes (a step whose child run was never created, the
 *    final consumer, an uncommitted settlement) and apply it. This is what
 *    no turn re-dispatch can see: repair at the graph layer.
 * 3. **Turn sweep** — for every active turn-executing run, re-derive its
 *    owed turn and dispatch; the case a purged queue leaves behind.
 *
 * Safe to run at any time: every dispatch is the same work committed state
 * already implies, and claim + state checks make an extra delivery a
 * no-op. Meant for operators, not the steady-state path — with one exception:
 * `--startup` is the container's boot sequence calling it, and is gated on
 * fleet ownership rather than on an operator's judgement.
 */
#[AsCommand(
    name: 'app:run:requeue',
    description: 'Re-derive and dispatch the work active runs are owed, including step graphs whose advancement was lost (recovery).',
)]
final class RunRequeueCommand extends Command
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly RunEngine $engine,
        private readonly MessageBusInterface $bus,
        private readonly RunGraph $graph,
        private readonly ClaimReaper $reaper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::OPTIONAL, 'Requeue only this run (default: every active run)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be dispatched, dispatch nothing')
            ->addOption('startup', null, InputOption::VALUE_NONE, 'Boot sweep: first clear claims a dead fleet abandoned, then requeue. Fleet-owner only; a no-op otherwise (SPEC §6.2).')
            ->setHelp(<<<'TXT'
                Re-derives the owed work for active runs (queued / running) and
                dispatches it: first any graph advancement a parent run owes
                (step children / final consumer / settlement), then the turn
                each run is owed. Use after a queue table was purged or the
                database was restored from a backup; healthy runs receive a
                duplicate delivery that the engine drops as stale.

                --startup is the container's boot path: it additionally clears
                execution claims abandoned more than CLAIM_STALE_SECONDS ago,
                which is what a fleet that was killed rather than stopped
                leaves behind. It does nothing unless this process group owns
                the fleet (TASKLOOM_FLEET_OWNER=1, set by the entrypoint's
                serve path), because a process that did not start the fleet
                cannot know nobody else is running.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = $input->getArgument('run-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $startup = (bool) $input->getOption('startup');

        if ($startup && null !== $runId) {
            $output->writeln('<error>--startup is a fleet-wide boot sweep and cannot be scoped to a single run.</error>');

            return self::FAILURE;
        }

        if ($startup && !$this->sweepAbandonedClaims($dryRun, $output)) {
            return self::SUCCESS;
        }

        $scope = null;
        if (null !== $runId) {
            $run = $this->runs->find((int) $runId);
            if (!$run instanceof Run) {
                $output->writeln(\sprintf('<error>No run with id %d.</error>', (int) $runId));

                return self::FAILURE;
            }
            $scope = $run;
        }

        $dispatched = $this->reconcileGraphs($scope, $dryRun, $output);
        $this->requeueTurns($scope, $dryRun, $output, $dispatched);

        return self::SUCCESS;
    }

    /**
     * S1 of the boot sweep: clear claims whose owner is gone.
     *
     * Returns whether the caller should go on to the dispatch sweeps.
     * `--startup` on a process that does not own the fleet returns false —
     * not because the reap would be unsafe there (ClaimReaper's staleness
     * window makes it safe anywhere), but because a process that did not
     * start the fleet has no business dispatching work it cannot see the
     * consumers of. A one-shot container can therefore run the boot sequence
     * unconditionally and be correct either way.
     */
    private function sweepAbandonedClaims(bool $dryRun, OutputInterface $output): bool
    {
        $ownership = FleetOwnership::fromProcessEnv();

        if (FleetOwnership::Unrecognized === $ownership) {
            // Fail closed but loudly: a deployment must never believe a sweep
            // is armed when it is not.
            $this->logger->warning(
                'Ignoring an unrecognized {var}; expected one of {accepted}. No claims swept.',
                [
                    'var' => FleetOwnership::ENV,
                    'accepted' => implode(', ', FleetOwnership::acceptedValues()),
                    'value' => getenv(FleetOwnership::ENV),
                ],
            );
            $output->writeln(\sprintf(
                '<comment>%s is not a recognized value; expected one of %s. Treating this process as not the fleet owner.</comment>',
                FleetOwnership::ENV,
                implode(', ', FleetOwnership::acceptedValues()),
            ));
        }

        if (!$ownership->ownsFleet()) {
            $output->writeln(\sprintf(
                'Not the fleet owner (%s is not set) — no claims swept, nothing dispatched.',
                FleetOwnership::ENV,
            ));

            return false;
        }

        $mine = FleetId::current();

        if (null === $mine) {
            // Not fatal any more — the sweep decides on age, not on who held
            // the claim — but the owner label is what makes a leftover claim
            // legible after the fact, so a fleet that is not stamping one is
            // worth saying out loud. (Set only by the entrypoint's serve path;
            // a manual `app:run:requeue` from a terminal legitimately has none.)
            $output->writeln(\sprintf(
                '<comment>No %s set — claims from this process will carry no owner label.</comment>',
                FleetId::ENV,
            ));
        }

        $grabAfter = $this->reaper->grabAfterSeconds();
        $survey = $this->reaper->survey();
        $reaped = $dryRun ? 0 : $this->reaper->reap();

        if (!$dryRun) {
            $this->logger->info('Boot sweep cleared {reaped} claim(s); {leased} left to the lease (fresher than the {grabAfter}s bound); {unowned} of the cleared had no owner label.', [
                'reaped' => $reaped,
                'fleet' => $mine,
                'grabAfter' => $grabAfter,
                'leased' => $survey['leased'],
                'unowned' => $survey['unowned'],
            ]);
        }

        // The bound is *always* printed, cleared or not: whether a leftover
        // claim is repaired at boot or waits out the engine's staleness window
        // is entirely a function of it, and an operator who cannot see the
        // number in force cannot tell a slow recovery from a broken one.
        $bound = 0 === $grabAfter
            ? 'any age'
            : \sprintf('older than %ds', $grabAfter);

        $output->writeln($dryRun
            ? \sprintf(
                'Claims: %d would be cleared (%s), %d left to the lease, %d of those without an owner label.',
                $survey['clearable'],
                $bound,
                $survey['leased'],
                $survey['unowned'],
            )
            : \sprintf(
                'Claims: cleared %d (%s), %d left to the lease, %d of those without an owner label.',
                $reaped,
                $bound,
                $survey['leased'],
                $survey['unowned'],
            ));

        return true;
    }

    /**
     * The graph half of the sweep: for each active parent in scope, derive
     * what the committed graph owes and apply (or report) it. Returns the
     * child run ids whose first turns the reconcile dispatched — the turn
     * sweep must not dispatch those children a second time in the same
     * invocation (the claim would no-op it, but not dispatching it at all
     * is cheaper and keeps the report honest).
     *
     * @return array<int, true>
     */
    private function reconcileGraphs(?Run $scope, bool $dryRun, OutputInterface $output): array
    {
        if (null !== $scope) {
            $reconcile = RunRole::Parent === $scope->getRole() ? $this->graph->reconcileParent($scope, $dryRun) : null;
            $reconciles = null !== $reconcile ? [$reconcile] : [];
        } else {
            $reconciles = $this->graph->reconcileParents($dryRun);
        }

        $steps = 0;
        $finals = 0;
        $settlements = 0;
        $dispatched = [];

        foreach ($reconciles as $reconcile) {
            $steps += \count($reconcile->stepTitles);
            $finals += $reconcile->finalConsumer ? 1 : 0;
            $settlements += null !== $reconcile->settled ? 1 : 0;

            foreach ($reconcile->dispatchedRunIds as $id) {
                $dispatched[$id] = true;
            }

            $output->writeln($dryRun
                ? \sprintf(
                    'Parent %d: would dispatch %d step run(s)%s%s.',
                    $reconcile->parentId,
                    \count($reconcile->stepTitles),
                    $reconcile->finalConsumer ? ', the final consumer' : '',
                    null !== $reconcile->settled ? \sprintf(', settle as %s', $reconcile->settled->value) : '',
                )
                : \sprintf(
                    'Parent %d: dispatched %d step run(s)%s%s.',
                    $reconcile->parentId,
                    \count($reconcile->stepTitles),
                    $reconcile->finalConsumer ? ', the final consumer' : '',
                    null !== $reconcile->settled ? \sprintf(', settled as %s', $reconcile->settled->value) : '',
                ));
        }

        if ([] !== $reconciles) {
            $output->writeln($dryRun
                ? \sprintf('Graph reconcile: %d step run(s), %d final consumer(s), %d settlement(s) would be dispatched.', $steps, $finals, $settlements)
                : \sprintf('Graph reconcile: %d step run(s), %d final consumer(s), %d settlement(s) dispatched.', $steps, $finals, $settlements));
        }

        return $dispatched;
    }

    /**
     * The turn half of the sweep: re-derive and dispatch each active
     * turn-executing run's owed turn, skipping children whose first turn
     * the graph reconcile already dispatched in this invocation.
     *
     * @param array<int, true> $alreadyDispatched
     */
    private function requeueTurns(?Run $scope, bool $dryRun, OutputInterface $output, array $alreadyDispatched): void
    {
        $runs = null !== $scope ? [$scope] : $this->runs->findActive();

        $dispatched = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            if (isset($alreadyDispatched[(int) $run->getId()])) {
                continue;
            }

            $message = $this->engine->nextTurnMessage($run);

            if (null === $message) {
                // Terminal (or paused): nothing is owed.
                ++$skipped;

                continue;
            }

            if ($dryRun) {
                $output->writeln(\sprintf('Run %d (%s): would dispatch %s.', $run->getId(), $run->getStatus()->value, $message::class));
                ++$dispatched;

                continue;
            }

            $this->bus->dispatch($message);
            $output->writeln(\sprintf('Run %d (%s): dispatched %s.', $run->getId(), $run->getStatus()->value, $message::class));
            ++$dispatched;
        }

        $output->writeln($dryRun
            ? \sprintf('%d run(s) would be requeued, %d skipped.', $dispatched, $skipped)
            : \sprintf('%d run(s) requeued, %d skipped.', $dispatched, $skipped));
    }
}
