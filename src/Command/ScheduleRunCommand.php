<?php

declare(strict_types=1);

namespace App\Command;

use App\Scheduler\TaskScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The scheduler daemon (SPEC §14): tick on an interval, forever.
 *
 * What the container's `serve` fleet runs, so a deployment with no
 * external cron still fires scheduled tasks. One tick per minute by
 * default — cron granularity is a minute, so ticking faster only adds
 * work; a tick that arrives late still catches the slot up (the cursor is
 * the authority, not wall-clock equality).
 *
 * Shuts down gracefully on SIGTERM/SIGINT: the current tick finishes, the
 * loop exits, the process returns 0. A stop can therefore never abandon a
 * launch mid-transaction — the occurrence is either fully consumed or
 * fully owed, and the next process's first tick picks up whatever is owed.
 *
 * Long-lived hygiene: the identity map is cleared after every tick, so
 * entities (and their snapshots) do not accumulate for the daemon's life.
 */
#[AsCommand(
    name: 'app:schedule:run',
    description: 'Run the scheduler daemon: tick on an interval, forever (what the container fleet runs).',
)]
final class ScheduleRunCommand extends Command implements SignalableCommandInterface
{
    private const int DEFAULT_INTERVAL_SECONDS = 60;

    private bool $stop = false;

    public function __construct(
        private readonly TaskScheduler $scheduler,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Seconds between ticks (default 60 — cron granularity is a minute)', (string) self::DEFAULT_INTERVAL_SECONDS)
            ->addOption('max-ticks', null, InputOption::VALUE_REQUIRED, 'Exit after N ticks (testing / one-shot; 0 = run forever)', '0')
            ->setHelp(<<<'TXT'
                Ticks on an interval until stopped: SIGTERM/SIGINT finish the
                current tick, then exit 0. The container entrypoint's `serve`
                fleet runs this as the `scheduler` process; the entrypoint's
                shutdown timeout bounds how long a stop waits for it.

                A tick missed while this daemon was down is recovered by the
                next one — a due task stays due until fired.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $interval = max(1, (int) $input->getOption('interval'));
        $maxTicks = max(0, (int) $input->getOption('max-ticks'));

        $io->writeln(\sprintf('Scheduler daemon running — tick every %ds.', $interval));

        $ticks = 0;

        while (!$this->stop) {
            ++$ticks;

            $result = $this->scheduler->tick(new \DateTimeImmutable());

            foreach ($result->fired as $entry) {
                $io->writeln(\sprintf('Fired task #%d — run #%d queued.', $entry['task']->getId(), $entry['run']->getId()));
            }
            foreach ($result->failed as $entry) {
                $io->writeln(\sprintf('<error>Task #%d failed to launch: %s</error>', $entry['task']->getId(), $entry['reason']));
            }
            foreach ($result->deferred as $entry) {
                $io->writeln(\sprintf('<error>Task #%d deferred: %s</error>', $entry['task']->getId(), $entry['reason']));
            }

            // Long-lived process hygiene: drop the identity map so nothing
            // from this tick survives into the next. tick() flushes/commits
            // internally; nothing here depends on in-memory state.
            $this->em->clear();

            if ($maxTicks > 0 && $ticks >= $maxTicks) {
                return self::SUCCESS;
            }

            // The signal handler sets this asynchronously (the console
            // Application arms pcntl async signals); PHPStan cannot see the
            // dispatch, hence the documented ignore.
            if ($this->stop) { // @phpstan-ignore if.alwaysFalse (set by handleSignal, delivered asynchronously)
                break;
            }

            // sleep() is interrupted by signal delivery, so a stop that
            // arrives mid-interval wakes the daemon immediately.
            sleep($interval);
        }

        $io->writeln('Scheduler daemon stopped.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    #[\Override]
    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    /**
     * A stop lands after the current tick: the loop checks $stop before
     * sleeping and exits, reporting success — a deliberate stop is not an
     * error. A signal during the sleep wakes it immediately.
     */
    #[\Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stop = true;

        return false;
    }
}
