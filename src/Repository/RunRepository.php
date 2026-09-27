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
     * Top-level runs only — standalone runs and the parent aggregators of
     * stepped tasks. Under run-per-step, a failing child settles its parent
     * with the same status and error class (SPEC §13.5), so the parent is
     * the unit the operator acts on; children appear inside their parent's
     * run page, not as separate queue rows. (A child of a graph is never
     * left behind in attention: the graph settles its parent the moment any
     * child fails terminally, and the queue's whole purpose is "give this
     * run a human eye".)
     *
     * @return list<Run>
     */
    public function findAttention(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.parent IS NULL')
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
     * Recent runs across all tasks, newest first — the run history list
     * (SPEC §8). Top-level runs only, for the same reason findAttention()
     * is: a stepped task's run is its parent aggregator, and the children
     * are visible on the run's own page.
     *
     * @return list<Run>
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.parent IS NULL')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * For a batch of task ids, the latest top-level run of each — one
     * query for the task list's status column instead of N.
     *
     * @param list<int> $taskIds
     *
     * @return array<int, Run> task id → its newest run
     */
    public function findLatestForTasks(array $taskIds): array
    {
        if ([] === $taskIds) {
            return [];
        }

        // Order newest-first and keep the first run seen per task: with
        // SQLite (and the SQL standard generally) there is no clean
        // portable "greatest-n-per-group" DQL, and the id-tiebroken order
        // makes the first row per task exactly the newest.
        $rows = $this->createQueryBuilder('r')
            ->where('r.task IN (:taskIds)')
            ->andWhere('r.parent IS NULL')
            ->setParameter('taskIds', $taskIds)
            ->orderBy('r.id', 'DESC')
            ->getQuery()->getResult();

        $latest = [];
        foreach ($rows as $run) {
            $taskId = $run->getTask()->getId();
            if (null !== $taskId && !isset($latest[$taskId])) {
                $latest[$taskId] = $run;
            }
        }

        return $latest;
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
