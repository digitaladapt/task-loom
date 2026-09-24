<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Run;
use App\Repository\RunRepository;
use App\RunEngine\RunEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Recovery: re-dispatch the turn each active run is owed (SPEC §6).
 *
 * The transport redelivers messages whose worker died, and duplicate
 * deliveries are adjudicated by the execution claim — but a message lost in
 * ways redelivery cannot see (queue table purged, database restored from a
 * backup) leaves a run with committed state and no carrier. The run row is
 * the durable queue of record; this command re-derives the owed turn from
 * it and dispatches.
 *
 * Safe to run at any time: the dispatched message is the same turn the run
 * state already implies, and if a carrier is still out there the claim +
 * state dedup make the extra delivery a no-op. Meant for operators, not the
 * steady-state path.
 */
#[AsCommand(
    name: 'app:run:requeue',
    description: 'Re-dispatch the owed turn for active runs whose queue message was lost (recovery).',
)]
final class RunRequeueCommand extends Command
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly RunEngine $engine,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::OPTIONAL, 'Requeue only this run (default: every active run)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be dispatched, dispatch nothing')
            ->setHelp(<<<'TXT'
                Re-derives the owed turn for active runs (queued / running) and
                dispatches it. Use after a queue table was purged or the
                database was restored from a backup; healthy runs receive a
                duplicate delivery that the engine drops as stale.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = $input->getArgument('run-id');
        $dryRun = (bool) $input->getOption('dry-run');

        if (null !== $runId) {
            $run = $this->runs->find((int) $runId);
            if (!$run instanceof Run) {
                $output->writeln(\sprintf('<error>No run with id %d.</error>', (int) $runId));

                return self::FAILURE;
            }
            $runs = [$run];
        } else {
            $runs = $this->runs->findActive();
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($runs as $run) {
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

        return self::SUCCESS;
    }
}
