<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Run queries: the FIFO queue, the attention queue, and run history.
 *
 * @extends ServiceEntityRepository<Run>
 */
final class RunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    /**
     * The persisted FIFO ready queue (SPEC §6): crash/restart keeps waiting
     * tasks waiting.
     *
     * @return list<Run>
     */
    public function findQueued(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :queued')
            ->setParameter('queued', RunStatus::Queued)
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Every active run (queued or running), oldest first: the recovery pass
     * behind app:run:requeue re-dispatches the turn each one is owed.
     *
     * @return list<Run>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', [RunStatus::Queued, RunStatus::Running])
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * The attention queue (SPEC §8): needs_attention and incomplete runs,
     * newest first.
     *
     * @return list<Run>
     */
    public function findAttention(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', [RunStatus::NeedsAttention, RunStatus::Incomplete])
            ->orderBy('r.finishedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Run history for a task, newest first. Top-level runs only — standalone
     * runs and the parent aggregators of stepped tasks, the unit the task
     * detail page lists as "a run of the task" (SPEC §13.6). Child runs of
     * a graph are reached through their parent (findChildren()).
     *
     * @return list<Run>
     */
    public function findForTask(Task $task): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.task = :task')
            ->andWhere('r.parent IS NULL')
            ->setParameter('task', $task)
            ->orderBy('r.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * The children of a parent run, oldest first (creation order — which for
     * the graph is also dispatch order). The parent's status is derived from
     * these (SPEC §13.3).
     *
     * @return list<Run>
     */
    public function findChildren(Run $parent): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.parent = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Failure-class rollup for a run (SPEC §5.3): errorClass → count, the
     * thing task-weaver never gave us.
     *
     * @return array<string, int>
     */
    public function errorClassRollup(Run $run): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('e.errorClass, COUNT(e.id) as cnt')
            ->from(\App\Entity\RunEvent::class, 'e')
            ->where('e.run = :run')
            ->andWhere('e.errorClass IS NOT NULL')
            ->groupBy('e.errorClass')
            ->setParameter('run', $run)
            ->getQuery()->getArrayResult();

        $rollup = [];
        foreach ($rows as $row) {
            $class = $row['errorClass'];
            $rollup[$class instanceof ErrorClass ? $class->value : (string) $class] = (int) $row['cnt'];
        }

        return $rollup;
    }

    public function save(Run $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    public function remove(Run $run): void
    {
        $this->getEntityManager()->remove($run);
        $this->getEntityManager()->flush();
    }
}
