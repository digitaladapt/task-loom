<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Run;
use App\Entity\RunEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * RunEvent queries: the ledger timeline for the run history view.
 *
 * @extends ServiceEntityRepository<RunEvent>
 */
final class RunEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RunEvent::class);
    }

    /**
     * The run's timeline, ordered by seq (SPEC §8: filterable by error
     * class in the UI).
     *
     * @return list<RunEvent>
     */
    public function findTimeline(Run $run): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.run = :run')
            ->setParameter('run', $run)
            ->orderBy('e.seq', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Ledger events of a given type for a run (e.g. all circuit_breaker
     * events, or the completion artifact).
     *
     * @return list<RunEvent>
     */
    public function findForRunByType(Run $run, \App\Entity\RunEventType $type): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.run = :run')
            ->andWhere('e.type = :type')
            ->setParameter('run', $run)
            ->setParameter('type', $type)
            ->orderBy('e.seq', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(RunEvent $event): void
    {
        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();
    }
}
