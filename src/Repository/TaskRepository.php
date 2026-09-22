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
            ->orderBy('t.updatedAt', 'ASC');

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
            ->orderBy('t.updatedAt', 'ASC')
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
            ->orderBy('t.updatedAt', 'DESC')
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
