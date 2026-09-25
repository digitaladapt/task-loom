<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Step;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Step queries: a task's step graph, in display order (SPEC §13).
 *
 * @extends ServiceEntityRepository<Step>
 */
final class StepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Step::class);
    }

    /**
     * The full step graph of a task, in display order. Dependency edges
     * (depends_on) are what execution reads; position is presentation only.
     *
     * @return list<Step>
     */
    public function findForTask(Task $task): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.task = :task')
            ->setParameter('task', $task)
            ->orderBy('s.position', \SortDirection::Ascending)
            ->addOrderBy('s.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    public function save(Step $step): void
    {
        $this->getEntityManager()->persist($step);
        $this->getEntityManager()->flush();
    }

    public function remove(Step $step): void
    {
        $this->getEntityManager()->remove($step);
        $this->getEntityManager()->flush();
    }
}
