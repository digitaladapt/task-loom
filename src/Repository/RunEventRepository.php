<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ErrorClass;
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
     * class in the UI — the optional filter is applied here so one query
     * returns exactly what the page renders).
     *
     * @return list<RunEvent>
     */
    public function findTimeline(Run $run, ?ErrorClass $errorClass = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.run = :run')
            ->setParameter('run', $run)
            ->orderBy('e.seq', \SortDirection::Ascending);

        if (null !== $errorClass) {
            $qb->andWhere('e.errorClass = :errorClass')
                ->setParameter('errorClass', $errorClass);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The error classes present in a run's ledger, so the filter control
     * can list exactly the classes this run actually hit.
     *
     * @return list<ErrorClass>
     */
    public function distinctErrorClasses(Run $run): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.errorClass')
            ->where('e.run = :run')
            ->andWhere('e.errorClass IS NOT NULL')
            ->setParameter('run', $run)
            ->getQuery()->getSingleColumnResult();

        $classes = [];
        foreach ($rows as $value) {
            if ($value instanceof ErrorClass) {
                $classes[] = $value;
            } elseif (\is_string($value)) {
                $enum = ErrorClass::tryFrom($value);
                if (null !== $enum) {
                    $classes[] = $enum;
                }
            }
        }

        return $classes;
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
            ->orderBy('e.seq', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    public function save(RunEvent $event): void
    {
        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();
    }
}
