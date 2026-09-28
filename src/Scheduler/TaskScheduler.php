<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunTrigger;
use App\Entity\Task;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\RunLauncher;
use App\RunEngine\RunLaunchException;
use App\RunEngine\ToolboxResolutionException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The scheduler tick (SPEC §14): fire the occurrences enabled scheduled
 * tasks are owed.
 *
 * The cursor is the record of truth. `task.next_run_at` holds the next
 * owed occurrence as an epoch instant; a task is due when its cursor has
 * arrived — or already passed, so a delayed tick (or a daemon that was
 * down) catches the slot up instead of skipping it. The tick:
 *
 *   arm    cursor is null → set it to the next occurrence after now
 *          (a freshly enabled task starts on its schedule; it does not
 *          fire retroactively)
 *   fire   cursor <= now → claim the occurrence with a compare-and-swap
 *          on the cursor itself (at-most-once, even if two ticks race),
 *          then launch through RunLauncher — the same queue path as
 *          Run now, so a scheduled run is born exactly like a manual one
 *          and is carried by the worker lanes, never by this process.
 *
 * Deliberate semantics, written down because they are policy:
 *
 *  - A due occurrence is skipped (left owed, reported) while a previous
 *    top-level run of the same task is still active. Two overlapping runs
 *    of one task is duplicate work by default, not the intent; the owed
 *    occurrence fires as soon as the previous run settles. Occurrences
 *    that pile up during a long run collapse into that one catch-up fire —
 *    the cursor always advances to the next occurrence after now.
 *  - Downtime never skips a slot by accident: on restart, the first tick
 *    catches up (one fire, not one per missed slot).
 *  - A launch that fails at dispatch for a classified reason (a toolbox
 *    that no longer resolves) becomes a FAILED run in the ledger, with its
 *    error class, and the occurrence is consumed — loud, not silent, and
 *    no retry storm. An infrastructure failure (the launch could not
 *    commit at all) rolls the occurrence back so the next tick retries it.
 *  - Only this class writes next_run_at. Enable/approve validate the
 *    expression; arming and advancing are the tick's job.
 */
final readonly class TaskScheduler
{
    public function __construct(
        private TaskRepository $tasks,
        private RunRepository $runs,
        private RunLauncher $launcher,
        private ScheduleExpression $expression,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $timezone,
    ) {
    }

    /**
     * The deployment timezone schedules are evaluated in — the clock the
     * operator authors and reads. Exposed for the reporting surfaces (the
     * tick command's output), which must speak the same wall clock.
     */
    public function timezone(): \DateTimeZone
    {
        return ScheduleExpression::resolveTimezone($this->timezone);
    }

    /**
     * One tick: arm what is new, fire what is due, report everything.
     *
     * Deterministic in $now — the caller owns the clock, so the tick is
     * testable without sleeping.
     */
    public function tick(\DateTimeImmutable $now): TickResult
    {
        $result = new TickResult();

        // Collect ids first, then re-find each task per iteration: a launch
        // that rolls back clears the identity map (the engine's transaction
        // pattern), and detached entities must not leak into the next task.
        // Same pattern as RunGraph::reconcileParents().
        $ids = [];
        foreach ($this->tasks->findRunnable() as $task) {
            $id = $task->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        foreach ($ids as $id) {
            $task = $this->tasks->find($id);
            if (!$task instanceof Task) {
                continue;
            }

            // The tick process is long-lived and the world changes under it
            // (tasks enabled in the UI, runs settling on the lanes): judge
            // the persisted row, never a stale identity-map snapshot.
            $this->em->refresh($task);

            $schedule = ScheduleExpression::normalize($task->getSchedule());
            if (null === $schedule) {
                continue; // Run now only — exactly v1.
            }

            $cursor = $task->getNextRunAt();
            if (null === $cursor) {
                $this->arm($task, $schedule, $now, $result);

                continue;
            }

            if ($cursor > $now->getTimestamp()) {
                continue; // Not due yet.
            }

            $this->fireDue($task, $schedule, $now, $result);
        }

        if (!$result->isEmpty()) {
            $this->logger->info('Scheduler tick: {fired} fired, {armed} armed, {skipped} skipped, {failed} failed, {deferred} deferred.', [
                'fired' => \count($result->fired),
                'armed' => \count($result->armed),
                'skipped' => \count($result->skipped),
                'failed' => \count($result->failed),
                'deferred' => \count($result->deferred),
            ]);
        }

        return $result;
    }

    /**
     * When this task would next fire, for display: the cursor when armed
     * (it is the authoritative next occurrence), otherwise the next
     * occurrence after $now for an enabled scheduled task that has not
     * been armed yet. Null when the task is not scheduled. Returns the
     * wall clock in the deployment timezone.
     */
    public function nextOccurrence(Task $task, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $timezone = ScheduleExpression::resolveTimezone($this->timezone);

        $cursor = $task->getNextRunAt();
        if (null !== $cursor) {
            return (new \DateTimeImmutable('@'.$cursor))->setTimezone($timezone);
        }

        $schedule = ScheduleExpression::normalize($task->getSchedule());
        if (null === $schedule || !$task->isEnabled() || $task->isArchived() || $task->isSuperseded()) {
            return null;
        }

        try {
            return $this->expression->nextAfter($schedule, $now, $this->timezone);
        } catch (ScheduleFormatException) {
            return null; // Corrupt data, not a display failure; the tick reports it.
        }
    }

    /**
     * Arm a fresh task: the first tick that sees an enabled scheduled task
     * sets its cursor to the next occurrence. Compare-and-swap on NULL so
     * two racing ticks arm it once — and never advance a cursor that was
     * armed microseconds earlier past an imminent occurrence.
     */
    private function arm(Task $task, string $schedule, \DateTimeImmutable $now, TickResult $result): void
    {
        try {
            $next = $this->expression->nextAfter($schedule, $now, $this->timezone);
        } catch (ScheduleFormatException $e) {
            $result->deferred[] = ['task' => $task, 'reason' => \sprintf('cannot arm: %s', $e->getMessage())];

            return;
        }

        $affected = (int) $this->em->createQuery(
            'UPDATE App\\Entity\\Task t SET t.nextRunAt = :next WHERE t.id = :id AND t.nextRunAt IS NULL',
        )
            ->setParameter('next', $next->getTimestamp())
            ->setParameter('id', $task->getId())
            ->execute();

        $this->em->refresh($task);

        if (1 === $affected) {
            $result->armed[] = ['task' => $task, 'next' => $next->getTimestamp()];
        }
    }

    /**
     * A due occurrence: claim it, then launch. Both effects — the advanced
     * cursor and the created run with its first lane message — commit in
     * one transaction, so the occurrence is never both consumed and lost.
     */
    private function fireDue(Task $task, string $schedule, \DateTimeImmutable $now, TickResult $result): void
    {
        $active = $this->runs->findActiveTopLevelFor($task);
        if ([] !== $active) {
            $result->skipped[] = [
                'task' => $task,
                'reason' => \sprintf(
                    'a previous run of this task is still active (run #%d, %s); the owed occurrence fires when it settles',
                    $active[0]->getId(),
                    $active[0]->getStatus()->value,
                ),
            ];

            return;
        }

        try {
            $next = $this->expression->nextAfter($schedule, $now, $this->timezone);
        } catch (ScheduleFormatException $e) {
            $result->deferred[] = ['task' => $task, 'reason' => \sprintf('cannot advance the cursor: %s', $e->getMessage())];

            return;
        }

        $fired = null;
        $failed = null;
        $deferred = null;

        try {
            $this->transactional(function () use ($task, $now, $next, &$fired, &$failed, &$deferred): void {
                // Claim the occurrence: advance the cursor only if it is
                // still the one we judged due. A lost race (another tick
                // consumed it) affects zero rows and this tick steps aside.
                $affected = (int) $this->em->createQuery(
                    'UPDATE App\\Entity\\Task t SET t.nextRunAt = :next WHERE t.id = :id AND t.nextRunAt <= :now',
                )
                    ->setParameter('next', $next->getTimestamp())
                    ->setParameter('id', $task->getId())
                    ->setParameter('now', $now->getTimestamp())
                    ->execute();

                if (1 !== $affected) {
                    return;
                }

                try {
                    $launch = $this->launcher->launch($task);
                    $launch->run->setTriggeredBy(RunTrigger::Scheduled);
                    $this->em->flush();

                    $fired = ['task' => $task, 'run' => $launch->run];
                } catch (ToolboxResolutionException $e) {
                    // A classified dispatch failure: the occurrence happened
                    // and failed loudly. Record it as a failed run so the
                    // operator sees it in the ledger and the attention
                    // queue, and consume the occurrence (no retry storm).
                    $failed = ['task' => $task, 'run' => $this->recordDispatchFailure($task, $e), 'reason' => $e->getMessage()];
                } catch (RunLaunchException $e) {
                    // The run was created but owes no dispatchable turn —
                    // "should be unreachable". The run row is the durable
                    // queue of record, so keep it and let app:run:requeue
                    // repair the missing carrier; report it as a problem.
                    $deferred = ['task' => $task, 'reason' => \sprintf('%s The run was kept for app:run:requeue to repair.', $e->getMessage())];
                }
            });
        } catch (\Throwable $e) {
            // The launch rolled back: the cursor advance went with it, so
            // the occurrence is still owed and the next tick retries it.
            // Never silently skipped. (No entity access here: the rollback
            // may have cleared the identity map; the next iteration
            // re-finds, and the detached instance is fine for reporting.)
            $result->deferred[] = ['task' => $task, 'reason' => \sprintf('launch rolled back, occurrence left owed: %s', $e->getMessage())];
            $this->logger->error('Scheduled launch of task {task} rolled back: {reason}', [
                'task' => $task->getId(),
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $this->em->refresh($task);

        if (null !== $fired) {
            $result->fired[] = $fired;
        } elseif (null !== $failed) {
            $result->failed[] = $failed;
        } elseif (null !== $deferred) {
            $result->deferred[] = $deferred;
        }
    }

    /**
     * A scheduled launch that failed at dispatch, as a terminal ledger row
     * (the same discipline RunGraph applies to a child whose constitution
     * cannot resolve): the run exists, carries the trigger, and states the
     * classified reason. It consumes the occurrence like any other fire.
     */
    private function recordDispatchFailure(Task $task, ToolboxResolutionException $e): Run
    {
        $run = new Run($task);
        $run->setTriggeredBy(RunTrigger::Scheduled);
        $run->markFailed($e->errorClass);

        $event = new RunEvent(RunEventType::Failure);
        $event->setPayload(['reason' => $e->getMessage()]);
        $event->setErrorClass($e->errorClass);
        $run->appendEvent($event);

        $this->em->persist($run);
        $this->em->persist($event);
        $this->em->flush();

        $this->logger->error('Scheduled run of task {task} failed at dispatch: {reason}', [
            'task' => $task->getId(),
            'reason' => $e->getMessage(),
        ]);

        return $run;
    }

    /**
     * The engine's commitTurn transaction pattern: own the transaction
     * when nobody above us does; roll back (and detach torn entities) on
     * any failure so a half-applied occurrence never persists.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $result = $work();

            if ($ownsTransaction) {
                $connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->em->clear(); // torn entities must not leak into the next task
            }

            throw $e;
        }
    }
}
