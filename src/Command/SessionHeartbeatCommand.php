<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Session\SessionAlarm;
use App\Session\SessionSliceEngine;
use App\Session\SessionState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The session heartbeat: beat on an interval, forever — the process that makes
 * an idle session safe.
 *
 * ## Why this must exist
 *
 * `app:schedule:run` and `app:run:requeue` between them cover scheduled tasks
 * and lost dispatches, but the requeue sweep only ever ran at BOOT (the
 * entrypoint calls it with `--startup`, fleet-owner gated). That leaves a hole
 * exactly the size of this feature: a session whose next slice is owed on a
 * CLOCK — a retry's backoff, or an idle stretch before a session decides what
 * to do next — has nobody to notice when the clock comes round. Boot happens
 * once; the wait can elapse minutes later. The session would sit in `owed` or
 * `retrying` until the container next restarted, which is the "unscheduled
 * session" failure the whole design is trying to eliminate.
 *
 * So this daemon re-derives the same truth the boot sweep does, on an interval.
 * Every beat it:
 *
 *  1. asks the slice engine to reconcile — which launches an owed slice, and a
 *     retrying slice only once its backoff has elapsed (the engine owns that
 *     judgement; the beat only supplies the clock);
 *  2. derives every enabled session's state, collects the ones that need a
 *     human, and publishes them to the alarm file `/health` reads.
 *
 * ## Why the alarm is derived here, and not written where a session stops
 *
 * "Which sessions are stopped" is a function of committed rows, and this
 * process is the one that already looks at all of them on a clock. Deriving it
 * here means the alarm cannot go stale and cannot disagree with the database:
 * an operator who resumes a session gets a clear sentinel on the next beat,
 * with no code path that has to remember to clear it.
 *
 * ## What a beat must never do
 *
 * Throw. A heartbeat that dies because one session's rows are malformed stops
 * doing the other job it has — catching owed work — and a supervisor restart
 * does not fix a data problem, so it would crash-loop instead. Every failure
 * is caught, logged, and retried next beat. Overlap with the scheduler daemon
 * is harmless: both call the same idempotent reconcile, and the slice engine
 * refuses a second slice for a working session.
 */
#[AsCommand(
    name: 'app:session:heartbeat',
    description: 'Beat on an interval: launch slices that have come due and publish stopped sessions to /health.',
)]
final class SessionHeartbeatCommand extends Command implements SignalableCommandInterface
{
    private const int DEFAULT_INTERVAL_SECONDS = 30;

    private bool $stop = false;

    public function __construct(
        private readonly SessionSliceEngine $slices,
        private readonly TaskRepository $tasks,
        private readonly SessionAlarm $alarm,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Seconds between beats (default 30 — well under the shortest retry delay)', (string) self::DEFAULT_INTERVAL_SECONDS)
            ->addOption('max-beats', null, InputOption::VALUE_REQUIRED, 'Exit after N beats (testing / one-shot; 0 = run forever)', '0')
            ->setHelp(<<<'TXT'
                Beats until stopped: SIGTERM/SIGINT finish the current beat, then
                exit 0.

                This is the process that makes an idle session safe. A session
                owed a slice on a clock — a retry's backoff, or an idle stretch
                before it decides what to do next — has nothing else to wake it,
                because the requeue sweep only runs at container boot. Each beat
                reconciles owed slices and republishes the alarm /health reads.

                The interval is deliberately shorter than the shortest retry
                delay, so a due retry launches within one beat of coming due
                rather than waiting for the next round.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $interval = max(1, (int) $input->getOption('interval'));
        $maxBeats = max(0, (int) $input->getOption('max-beats'));

        $io->writeln(\sprintf('Session heartbeat running — beat every %ds.', $interval));

        $beats = 0;

        while (!$this->stop) {
            ++$beats;

            $this->beat($io);

            // Long-lived hygiene, as the scheduler daemon does: drop the
            // identity map so nothing from this beat survives into the next.
            $this->em->clear();

            if ($maxBeats > 0 && $beats >= $maxBeats) {
                return self::SUCCESS;
            }

            // The signal handler sets this asynchronously, so this is a
            // genuine re-read of a property PHPStan believes cannot change.
            if ($this->stop) {
                break;
            }

            sleep($interval);
        }

        $io->writeln('Session heartbeat stopped.');

        return self::SUCCESS;
    }

    /**
     * One beat. Never throws: see the class note. The two halves are
     * independent, so a failed reconcile still publishes the alarm — the
     * alarm is the more important half when something is broken.
     */
    private function beat(SymfonyStyle $io): void
    {
        try {
            foreach ($this->slices->reconcile() as $reconcile) {
                if (null !== $reconcile->sliceRunId) {
                    $io->writeln(\sprintf('Launched slice run #%d for task #%d.', $reconcile->sliceRunId, $reconcile->taskId));
                }
            }
        } catch (\Throwable $e) {
            $io->writeln(\sprintf('<error>Beat could not reconcile sessions: %s</error>', $e->getMessage()));
        }

        try {
            $this->alarm->publish($this->stoppedSessions());
        } catch (\Throwable $e) {
            $io->writeln(\sprintf('<error>Beat could not publish the session alarm: %s</error>', $e->getMessage()));
        }
    }

    /**
     * Every enabled session that needs a human: `stopped` (a terminal failure,
     * an undeclared stop, or a spent retry budget) and `blocked` (the session
     * said so itself). Both are conditions a retry cannot fix and a clock
     * cannot resolve, which is exactly what makes them alarm-worthy.
     *
     * `paused` is deliberately NOT here — that is the operator's own switch,
     * and alarming on a decision somebody made on purpose trains people to
     * ignore the light.
     *
     * @return list<array{taskId: int, title: string, state: string, reason: string}>
     */
    private function stoppedSessions(): array
    {
        $stopped = [];

        foreach ($this->tasks->findEnabledSessions() as $task) {
            if (null === $task->getId()) {
                continue;
            }

            $state = $this->slices->state($task);

            if (SessionState::Stopped !== $state && SessionState::Blocked !== $state) {
                continue;
            }

            $stopped[] = [
                'taskId' => (int) $task->getId(),
                'title' => $task->getTitle(),
                'state' => $state->value,
                'reason' => $this->reason($task),
            ];
        }

        return $stopped;
    }

    /** The newest slice's own account of why it stopped, for the alarm body. */
    private function reason(Task $task): string
    {
        $last = $this->slices->lastSlice($task);

        if (null === $last) {
            return 'no slice has run';
        }

        $why = $last->getErrorClass();

        return \sprintf('run #%d %s%s', (int) $last->getId(), $last->getStatus()->value, null === $why ? '' : ' ('.$why->value.')');
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
     * A stop lands after the current beat: a deliberate stop is not an error,
     * and a signal during the sleep wakes the daemon immediately.
     */
    #[\Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stop = true;

        return false;
    }
}
