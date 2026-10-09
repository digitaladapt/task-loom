<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SessionMemory;
use App\Entity\SessionMemoryKind;
use App\Entity\SessionMemoryTier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Session-memory reads (docs/design/SESSION_TASKS.md §3): the objective, and
 * the notes by tier. The session is the namespace (§3.1), so every query is
 * scoped by task id alone — there is no key dimension to filter on.
 *
 * Note lists are returned oldest-first by creation (the order every caller
 * reasons in: ageing demotes the oldest, cold overflow drops the oldest).
 * Creation order, not `updated_at`: a note's text is never rewritten (only
 * its tier moves), so `updated_at` would say nothing about a note's age.
 *
 * @extends ServiceEntityRepository<SessionMemory>
 */
final class SessionMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionMemory::class);
    }

    /** The session's objective — at most one row, by construction (§3.2). */
    public function findObjectiveFor(int $taskId): ?SessionMemory
    {
        return $this->createQueryBuilder('m')
            ->where('m.task = :task')
            ->andWhere('m.kind = :kind')
            ->setParameter('task', $taskId)
            ->setParameter('kind', SessionMemoryKind::Objective)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The session's notes, oldest first, optionally filtered to one tier.
     *
     * @return list<SessionMemory>
     */
    public function findNotesFor(int $taskId, ?SessionMemoryTier $tier = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.task = :task')
            ->andWhere('m.kind = :kind')
            ->setParameter('task', $taskId)
            ->setParameter('kind', SessionMemoryKind::Note);

        if (null !== $tier) {
            $qb->andWhere('m.tier = :tier')
                ->setParameter('tier', $tier);
        }

        return $qb
            ->orderBy('m.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
