<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SessionMemory;
use App\Entity\SessionMemoryKind;
use App\Entity\SessionMemoryRevision;
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

    /**
     * One memory row by id, scoped to its session — the controller's lookup
     * for a UI action, so a request for another session's row finds nothing
     * rather than writing it.
     */
    public function findOneForTask(int $taskId, int $memoryId): ?SessionMemory
    {
        return $this->createQueryBuilder('m')
            ->where('m.task = :task')
            ->andWhere('m.id = :id')
            ->setParameter('task', $taskId)
            ->setParameter('id', $memoryId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every revision of every memory row of one session, newest first — the
     * UI's history list, one query for the page. The caller groups by
     * memory id (the revision's own `memory` association carries it).
     *
     * @return list<SessionMemoryRevision>
     */
    public function findRevisionsForTask(int $taskId): array
    {
        return $this->getEntityManager()
            ->createQuery('SELECT r FROM App\Entity\SessionMemoryRevision r JOIN r.memory m WHERE m.task = :task ORDER BY r.id DESC')
            ->setParameter('task', $taskId)
            ->getResult();
    }

    /**
     * Every revision of one memory row, newest first — what delete and
     * revert need to take (or apply) the history through the UnitOfWork.
     *
     * @return list<SessionMemoryRevision>
     */
    public function findRevisionsForMemory(SessionMemory $memory): array
    {
        return $this->getEntityManager()
            ->createQuery('SELECT r FROM App\Entity\SessionMemoryRevision r WHERE r.memory = :memory ORDER BY r.id DESC')
            ->setParameter('memory', $memory)
            ->getResult();
    }

    /**
     * One revision, scoped to its session and its memory row — the revert
     * action's lookup, so a request cannot revert another session's entry.
     */
    public function findRevisionForTask(int $taskId, int $memoryId, int $revisionId): ?SessionMemoryRevision
    {
        return $this->getEntityManager()
            ->createQuery('SELECT r FROM App\Entity\SessionMemoryRevision r JOIN r.memory m WHERE m.task = :task AND m.id = :memory AND r.id = :revision')
            ->setParameter('task', $taskId)
            ->setParameter('memory', $memoryId)
            ->setParameter('revision', $revisionId)
            ->getOneOrNullResult();
    }
}
