<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Chat;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ChatExchange queries: the exchanges still owed a reply, and one
 * conversation's exchanges.
 *
 * @extends ServiceEntityRepository<ChatExchange>
 */
final class ChatExchangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatExchange::class);
    }

    /**
     * Every exchange still owed work (queued or running), oldest first.
     *
     * The recovery counterpart of `RunRepository::findActive()`: an exchange
     * whose carrier message was lost — a purged queue, a restored backup —
     * has committed state and nothing to advance it, and this is what
     * `app:chat:requeue` walks. Oldest first so a human who has been waiting
     * longest is answered first.
     *
     * @return list<ChatExchange>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status IN (:statuses)')
            ->setParameter('statuses', [ChatExchangeStatus::Queued, ChatExchangeStatus::Running])
            ->orderBy('e.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * The exchanges of one conversation, oldest first.
     *
     * @return list<ChatExchange>
     */
    public function findForChat(Chat $chat): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.chat = :chat')
            ->setParameter('chat', $chat)
            ->orderBy('e.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * The most recent exchange of a conversation, or null when nothing has
     * been said yet.
     *
     * Used to decide whether the *previous* turn is still unanswered: a
     * message that lands while an exchange is running must not silently queue
     * into an exchange whose reply is being written against a transcript that
     * does not include it. The caller starts a fresh exchange instead.
     */
    public function findLatestForChat(Chat $chat): ?ChatExchange
    {
        return $this->createQueryBuilder('e')
            ->where('e.chat = :chat')
            ->setParameter('chat', $chat)
            ->orderBy('e.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
