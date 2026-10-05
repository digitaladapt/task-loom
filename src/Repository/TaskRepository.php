<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Task queries. The approval queue finder powers the admin UI (SPEC §8);
 * the active tasks finder feeds the scheduler in v1.1.
 *
 * @extends ServiceEntityRepository<Task>
 */
final class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * The approval queue (SPEC §8): un-archived, not-yet-enabled drafts —
     * agent-proposed tasks and replacement drafts awaiting a human decision.
     *
     * @return list<Task>
     */
    public function findApprovalQueue(): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.enabled = false')
            ->andWhere('t.archivedAt IS NULL')
            ->orderBy('t.updatedAt', \SortDirection::Ascending);

        return $qb->getQuery()->getResult();
    }

    /**
     * Tasks eligible for Run-now: enabled, not archived, not superseded.
     *
     * @return list<Task>
     */
    public function findRunnable(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.enabled = true')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.supersededBy IS NULL')
            ->orderBy('t.updatedAt', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * All replacement drafts for a given enabled task (the chain, newest
     * first) — the diff view in the approval queue.
     *
     * @return list<Task>
     */
    public function findReplacementDraftsFor(Task $task): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.replacementFor = :task')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('task', $task)
            ->orderBy('t.updatedAt', \SortDirection::Descending)
            ->getQuery()->getResult();
    }

    /**
     * Re-read a task's persisted state, discarding any stale in-memory
     * snapshot. TaskCrud refreshes before every write-gate decision (SPEC
     * §4.3): the MCP serve process is long-lived, and entities cached in
     * its identity map go stale when tasks are enabled or archived out of
     * band. The gate must judge the persisted row, not a cached snapshot.
     */
    public function refresh(Task $task): void
    {
        $this->getEntityManager()->refresh($task);
    }

    /**
     * Whether a task has ever run (SPEC §4.4). Part of the write gate: a task
     * that has run is an immutable record whether or not it is currently
     * enabled, so "has this version run?" has to be asked of the store and not
     * inferred from the task row.
     *
     * Deliberately uncached and unmemoized. The admin UI loads a task and then
     * calls TaskCrud in the same request, and the MCP serve process holds
     * entities across many; in both, a stale "no" is the write that corrupts a
     * record. The read is a COUNT on idx_run_task, which is the cheap side of
     * that trade.
     *
     * Scoped to the task, not the replacement chain: a draft has no runs of its
     * own, and a run of the version it replaces is a run of that version.
     */
    public function hasRuns(Task $task): bool
    {
        $taskId = $task->getId();
        if (null === $taskId) {
            return false;
        }

        return $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(\App\Entity\Run::class, 'r')
            ->where('r.task = :task')
            ->setParameter('task', $taskId)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Archived tasks, oldest first — the dead records (SPEC §4.4).
     *
     * @return list<Task>
     */
    public function findArchived(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.archivedAt IS NOT NULL')
            ->orderBy('t.archivedAt', \SortDirection::Descending)
            ->getQuery()->getResult();
    }

    public function save(Task $task): void
    {
        $this->getEntityManager()->persist($task);
        $this->getEntityManager()->flush();
    }

    public function remove(Task $task): void
    {
        $this->getEntityManager()->remove($task);
        $this->getEntityManager()->flush();
    }
}
