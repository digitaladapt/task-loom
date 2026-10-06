<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ChatExchangeEvent queries: the transcript read.
 *
 * ## The transcript is a filtered read, not a table
 *
 * There is no `ChatTurn` table (SPEC §15 — a decision, not an omission). The
 * transcript is the conversational rows of one conversation's exchanges, in
 * order, and that is the whole read. The reason it is cheap is the reason
 * `ChatExchangeEvent` keeps attribution as typed columns: the filter is on an
 * indexed enum column, not a scan of every payload.
 *
 * @extends ServiceEntityRepository<ChatExchangeEvent>
 */
final class ChatExchangeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatExchangeEvent::class);
    }

    /**
     * Every turn of a conversation, oldest first — what the model is shown
     * and what the page renders.
     *
     * Ordered by the ledger row's own id, which *is* the append order: events
     * are written as they happen, so id order is chronological order, and it
     * interleaves two exchanges correctly if a second message arrives before
     * the first reply lands. Ordering by `seq` alone would be wrong here,
     * because `seq` restarts at 1 in each exchange — it is a position *within*
     * an execution, not within the conversation.
     *
     * @return list<ChatExchangeEvent>
     */
    public function findTranscript(Chat $chat, int $limit = 200): array
    {
        $rows = $this->createQueryBuilder('e')
            ->join('e.exchange', 'x')
            ->where('x.chat = :chat')
            ->andWhere('e.type IN (:conversational)')
            ->setParameter('chat', $chat)
            ->setParameter('conversational', self::conversationalTypes())
            ->orderBy('e.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        // Fetched newest-first so the LIMIT keeps the most recent turns, then
        // reversed into reading order.
        return array_reverse($rows);
    }

    /**
     * The turns of one exchange, in ledger order — the exchange's own view,
     * used by the run-surface-style detail read.
     *
     * @return list<ChatExchangeEvent>
     */
    public function findForExchange(ChatExchange $exchange): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.exchange = :exchange')
            ->setParameter('exchange', $exchange)
            ->orderBy('e.seq', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * The conversational types of the chat vocabulary.
     *
     * Derived from `ChatEventType::isConversational()` rather than listed
     * literally, so adding a conversational type cannot leave a query behind
     * that silently omits it from every transcript in the system.
     *
     * @return list<ChatEventType>
     */
    private static function conversationalTypes(): array
    {
        return array_values(array_filter(
            ChatEventType::cases(),
            static fn (ChatEventType $type): bool => $type->isConversational(),
        ));
    }
}
