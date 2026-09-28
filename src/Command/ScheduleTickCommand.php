<?php

declare(strict_types=1);

namespace App\Command;

use App\Scheduler\TaskScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One scheduler tick (SPEC §14): arm fresh scheduled tasks, fire the owed
 * occurrences, report what happened.
 *
 * The one-shot form of app:schedule:run, for cron-style deployments and
 * for manual inspection. Exit code 0 means the tick ran; problems inside
 * it (a launch that failed or rolled back) are reported per task — the
 * tick itself is operational even when a task's launch was not.
 */
#[AsCommand(
    name: 'app:schedule:tick',
    description: 'Run one scheduler tick: fire the occurrences enabled scheduled tasks are owed.',
)]
final class ScheduleTickCommand extends Command
{
    public function __construct(
        private readonly TaskScheduler $scheduler,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setHelp(<<<'TXT'
            The scheduler tick (SPEC §14). A task is due when its cursor
            (task.next_run_at) has arrived — or already passed, so a delayed
            tick catches the slot up instead of skipping it. Due occurrences
            launch through the same queue path as Run now and are carried by
            the worker lanes.

            Usually run by the container's `serve` fleet as app:schedule:run;
            run this by hand to inspect, or from an external cron:

                * * * * * cd /app && php bin/console app:schedule:tick

            Safe to run at any time, as often as you like: the cursor is
            advanced by compare-and-swap, so a due occurrence fires at most
            once even if two ticks race.
            TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->scheduler->tick(new \DateTimeImmutable());

        if ($result->isEmpty()) {
            $io->writeln('Tick: nothing owed.');

            return self::SUCCESS;
        }

        foreach ($result->armed as $entry) {
            $io->writeln(\sprintf(
                'Armed task #%d "%s" — next run %s.',
                $entry['task']->getId(),
                $entry['task']->getTitle(),
                (new \DateTimeImmutable('@'.$entry['next']))->format('Y-m-d H:i'),
            ));
        }

        foreach ($result->fired as $entry) {
            $io->writeln(\sprintf(
                'Fired task #%d "%s" — run #%d queued.',
                $entry['task']->getId(),
                $entry['task']->getTitle(),
                $entry['run']->getId(),
            ));
        }

        foreach ($result->skipped as $entry) {
            $io->writeln(\sprintf(
                'Held task #%d "%s" — %s.',
                $entry['task']->getId(),
                $entry['task']->getTitle(),
                $entry['reason'],
            ));
        }

        foreach ($result->failed as $entry) {
            $io->writeln(\sprintf(
                '<error>Failed task #%d "%s" — %s (run #%d recorded).</error>',
                $entry['task']->getId(),
                $entry['task']->getTitle(),
                $entry['reason'],
                $entry['run']->getId(),
            ));
        }

        foreach ($result->deferred as $entry) {
            $io->writeln(\sprintf(
                '<error>Deferred task #%d "%s" — %s</error>',
                $entry['task']->getId(),
                $entry['task']->getTitle(),
                $entry['reason'],
            ));
        }

        return self::SUCCESS;
    }
}
