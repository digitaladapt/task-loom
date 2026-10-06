<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Chat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Chat queries: the conversation list.
 *
 * @extends ServiceEntityRepository<Chat>
 */
final class ChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chat::class);
    }

    /**
     * The conversations, most recently active first.
     *
     * Ordered by `updated_at` rather than `id`: the question a list of
     * conversations answers is "where was I?", not "which did I create
     * latest", and those differ as soon as an old conversation gets a new
     * message.
     *
     * @return list<Chat>
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.updatedAt', \SortDirection::Descending)
            ->addOrderBy('c.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
