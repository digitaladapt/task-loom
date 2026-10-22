<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Task;
use App\Entity\TaskKind;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use App\Scheduler\ScheduleExpression;
use App\Scheduler\ScheduleFormatException;
use App\Session\SessionConstraintException;
use App\Session\SessionMemoryStore;
use App\StepModel\StepGraphException;
use App\StepModel\StepGraphValidator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The human-only task lifecycle surface (SPEC §4.3, §4.4, §8).
 *
 * Enabling, approving, and archiving are manual, user-driven actions — the
 * human is the enable switch. This service is reachable only from the admin
 * UI controllers (user-authenticated sessions); the MCP task tools route
 * writes through TaskCrud instead, which cannot reach these actions.
 *
 * Every action is one atomic flush: lifecycle decisions must never land
 * half-applied (SPEC §4.4's approve is an atomic swap of two rows).
 *
 * Enable and approve are also the step-graph enforcement gate (SPEC
 * §13.2): a task whose step DAG is invalid — a cycle, a self-dependency,
 * an edge to another task's step — is refused before its rows move. An
 * invalid graph never becomes an enabled task.
 */
final class TaskAdminService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly StepRepository $steps,
        private readonly StepGraphValidator $stepGraph,
        private readonly ScheduleExpression $scheduleExpression,
        private readonly EntityManagerInterface $em,
        private readonly SessionMemoryStore $memory,
    ) {
    }

    /**
     * Enable a draft task (SPEC §4.3): the human approval action. Only
     * drafts — a replacement draft must be approved via approve(), and
     * archived tasks stay dead.
     *
     * @throws TaskLifecycleException when the task cannot be enabled
     */
    public function enableTask(int $taskId): Task
    {
        $task = $this->findOrThrow($taskId);

        if (null !== $task->getReplacementFor()) {
            throw new TaskLifecycleException(\sprintf('Task %d is a replacement draft — approve it instead (SPEC §4.4).', $taskId));
        }

        $this->guardKind($task, 'enable');
        $this->guardStepGraph($task, 'enable');
        $this->guardSchedule($task, 'enable');

        try {
            $task->enable();
        } catch (\LogicException $e) {
            throw new TaskLifecycleException($e->getMessage(), $e->getCode(), $e);
        }

        $this->em->flush();

        return $task;
    }

    /**
     * Approve a replacement draft (SPEC §4.4): atomic swap — B is enabled,
     * A is disabled, superseded, and archived. Either both rows change or
     * neither does (single flush, Doctrine transaction).
     *
     * A session's continuity moves with the swap (docs/design/
     * SESSION_TASKS.md §8.2). The successor is the same continuing entity under
     * a new row, so it takes over two things an approval would otherwise
     * silently drop:
     *
     * - **its memory** — for a session, the objective, the notes and the
     *   revision history are moved onto the successor ({@see
     *   SessionMemoryStore::carryTo()}), because `session_memory.task_id`
     *   cascades: without the move, approving the operator's own edit would
     *   hand the session an empty store and erase its history when the
     *   original's row goes.
     * - **its standing** — an approved replacement of a task that has run is
     *   a record, so it adopts the chain's `hasRuns` rather than arriving as a
     *   fresh draft with locked-down content and a `fresh` state.
     *
     * The runs stay on the version that produced them: the ledger is a record
     * of what happened to a specific enabled task, and neither the successor
     * nor the original should ever be able to rewrite it. Session state
     * therefore walks the supersede chain instead ({@see
     * SessionSliceEngine::activeState()}), which is what lets one session
     * span several approved versions.
     *
     * @throws TaskLifecycleException when the task cannot be approved
     */
    public function approveTask(int $taskId): Task
    {
        $task = $this->findOrThrow($taskId);

        $this->guardKind($task, 'approve');
        $this->guardStepGraph($task, 'approve');
        $this->guardSchedule($task, 'approve');

        $original = $task->getReplacementFor();

        try {
            $task->approve();
        } catch (\LogicException $e) {
            throw new TaskLifecycleException($e->getMessage(), $e->getCode(), $e);
        }

        if (null !== $original) {
            $task->adoptStanding($this->tasks->hasRunsInChain($original));

            // Only a session has a memory store (§3.1). A run task's approval
            // moves its standing and nothing else, and carryTo() rightly
            // refuses a non-session — so ask only when there is something to
            // move.
            if (TaskKind::Session === $task->getKind()) {
                $this->memory->carryTo($original, $task);
            }
        }

        $this->em->flush();

        return $task;
    }

    /**
     * Reject a replacement draft (SPEC §4.4): archive it; the original is
     * unaffected.
     *
     * @throws TaskLifecycleException when the task cannot be rejected
     */
    public function rejectTask(int $taskId): Task
    {
        $task = $this->findOrThrow($taskId);

        try {
            $task->reject();
        } catch (\LogicException $e) {
            throw new TaskLifecycleException($e->getMessage(), $e->getCode(), $e);
        }

        $this->em->flush();

        return $task;
    }

    /**
     * Archive a draft task the human does not want. Enabled tasks are not
     * archivable — disabling is the lifecycle action for them (v1 keeps it
     * simple: retire by approving a replacement, or leave it).
     *
     * @throws TaskLifecycleException when the task cannot be archived
     */
    public function archiveTask(int $taskId): Task
    {
        $task = $this->findOrThrow($taskId);

        if ($task->isEnabled()) {
            throw new TaskLifecycleException(\sprintf('Task %d is enabled — enabled tasks are immutable (SPEC §4.4).', $taskId));
        }

        if ($task->isArchived()) {
            throw new TaskLifecycleException(\sprintf('Task %d is already archived.', $taskId));
        }

        $task->archiveAsDraft();
        $this->em->flush();

        return $task;
    }

    /**
     * Disable an enabled task (SPEC §4.4): the pause. The task keeps its
     * content, its schedule, and its run history, but it is no longer
     * runnable — Run now refuses it and the scheduler tick skips it, because
     * both read the same enabled flag (findRunnable()). Enabling it again
     * resumes it, and the tick re-arms it on its schedule like any fresh
     * enable.
     *
     * Disabling is a lifecycle flag, not a content edit: it is the one
     * mutation an enabled task is allowed (SPEC §4.4), and it is reversible
     * in a way archiving is not — `archived_at` is a dead record, the
     * disabled flag is a pause.
     *
     * @throws TaskLifecycleException when the task is not enabled
     */
    public function disableTask(int $taskId): Task
    {
        $task = $this->findOrThrow($taskId);

        if ($task->isPendingReplacement()) {
            throw new TaskLifecycleException(\sprintf('Task %d is a replacement draft — there is nothing to disable (SPEC §4.4).', $taskId));
        }

        if (!$task->isEnabled()) {
            throw new TaskLifecycleException(\sprintf('Task %d is not enabled — only an enabled task can be disabled.', $taskId));
        }

        if ($task->isArchived()) {
            throw new TaskLifecycleException(\sprintf('Task %d is archived and cannot be disabled.', $taskId));
        }

        $task->disable();
        $this->em->flush();

        return $task;
    }

    /**
     * Session-kind gates (docs/design/SESSION_TASKS.md §8, build order step
     * 4): the constraints that outlived the blanket kind-gate. A session
     * cannot carry steps (a slice is already the unit of work, and a step's
     * run never gets the harness tools, so it could never declare) and
     * cannot be scheduled (it is started and stopped, not cron'd). Refused
     * here, at the enable/approve gate, the same discipline as the step
     * graph and the schedule — and again at the digester's write gate, so
     * one can never slip past the other.
     */
    private function guardKind(Task $task, string $action): void
    {
        if (TaskKind::Session !== $task->getKind()) {
            return;
        }

        if ([] !== $this->steps->findForTask($task)) {
            throw new TaskLifecycleException(\sprintf('Cannot %s task %d — %s', $action, $task->getId(), SessionConstraintException::stepsNotAllowed()->getMessage()));
        }

        if (null !== $task->getSchedule()) {
            throw new TaskLifecycleException(\sprintf('Cannot %s task %d — %s', $action, $task->getId(), SessionConstraintException::scheduleNotAllowed()->getMessage()));
        }
    }

    /**
     * Step-graph enforcement gate (SPEC §13.2): an invalid graph never
     * becomes an enabled task. Runs before the enable/approve flip; a
     * failure leaves the rows untouched (nothing was flushed yet).
     */
    private function guardStepGraph(Task $task, string $action): void
    {
        try {
            $this->stepGraph->assertValid($this->steps->findForTask($task));
        } catch (StepGraphException $e) {
            throw new TaskLifecycleException(\sprintf('Cannot %s task %d — %s', $action, $task->getId(), $e->getMessage()), 0, $e);
        }
    }

    /**
     * Schedule enforcement gate (SPEC §14): an invalid cron expression
     * never becomes an enabled task — the same discipline as the step
     * graph (§13.2). Runs before the enable/approve flip; a failure
     * leaves the rows untouched (nothing was flushed yet).
     */
    private function guardSchedule(Task $task, string $action): void
    {
        try {
            $this->scheduleExpression->assertValid($task->getSchedule());
        } catch (ScheduleFormatException $e) {
            throw new TaskLifecycleException(\sprintf('Cannot %s task %d — %s', $action, $task->getId(), $e->getMessage()), 0, $e);
        }
    }

    private function findOrThrow(int $taskId): Task
    {
        $task = $this->tasks->find($taskId);
        if (!$task instanceof Task) {
            throw new TaskLifecycleException(\sprintf('No task with id %d.', $taskId));
        }

        return $task;
    }
}
