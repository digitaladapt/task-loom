<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Task;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
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
        private readonly EntityManagerInterface $em,
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

        $this->guardStepGraph($task, 'enable');

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
     * @throws TaskLifecycleException when the task cannot be approved
     */
    public function approveTask(int $taskId): Task
    {
        $task = $this->findOrThrow($taskId);

        $this->guardStepGraph($task, 'approve');

        try {
            $task->approve();
        } catch (\LogicException $e) {
            throw new TaskLifecycleException($e->getMessage(), $e->getCode(), $e);
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

    private function findOrThrow(int $taskId): Task
    {
        $task = $this->tasks->find($taskId);
        if (!$task instanceof Task) {
            throw new TaskLifecycleException(\sprintf('No task with id %d.', $taskId));
        }

        return $task;
    }
}
