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
     * Run history for a task, newest first.
     *
     * @return list<Run>
     */
    public function findForTask(Task $task): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.task = :task')
            ->setParameter('task', $task)
            ->orderBy('r.id', 'DESC')
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
