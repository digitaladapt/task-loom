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
 * ChatExchangeEvent queries: the transcript read, and the wire read.
 *
 * ## The transcript is a filtered read, not a table
 *
 * There is no `ChatTurn` table (SPEC §15 — a decision, not an omission). The
 * transcript is the conversational rows of one conversation's exchanges, in
 * order, and that is the whole read. The reason it is cheap is the reason
 * `ChatExchangeEvent` keeps attribution as typed columns: the filter is on an
 * indexed enum column, not a scan of every payload.
 *
 * ## Two reads, deliberately different
 *
 * `findTranscript()` is what the *page* shows; `findWireTranscript()` is what
 * the *model* is sent. They differ by the tool rows, and conflating them is a
 * bug in either direction: the page would show tool plumbing as conversation,
 * or the model would be re-asked a question whose answer it already has.
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
     * What the conversation reads as: what was said, plus a line for each tool
     * the assistant used (SPEC §15, `CHAT_TOOLS.md`).
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
        // Fetched newest-first so the LIMIT keeps the most recent rows, then
        // reversed into reading order.
        return array_reverse($this->findForChatByTypes($chat, self::transcriptTypes(), $limit));
    }

    /**
     * Everything the model is shown: the turns *and* the tool exchanges.
     *
     * A conversation with tools has to send the model the calls it made and
     * the results it got — plus the assistant `tool_calls` message and the
     * `tool` result messages the endpoint requires as a matched pair.
     *
     * @return list<ChatExchangeEvent>
     */
    public function findWireTranscript(Chat $chat, int $limit = 200): array
    {
        return array_reverse($this->findForChatByTypes($chat, self::wireTypes(), $limit));
    }

    /**
     * The rows of one exchange, in ledger order — the exchange's own view.
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
     * @param list<ChatEventType> $types
     *
     * @return list<ChatExchangeEvent>
     */
    private function findForChatByTypes(Chat $chat, array $types, int $limit): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.exchange', 'x')
            ->where('x.chat = :chat')
            ->andWhere('e.type IN (:types)')
            ->setParameter('chat', $chat)
            ->setParameter('types', $types)
            ->orderBy('e.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * What the page shows: what was said, plus the tools the assistant used.
     *
     * Derived from the vocabulary's own predicates rather than listed
     * literally, so adding a type cannot leave a query behind that silently
     * omits it everywhere.
     *
     * @return list<ChatEventType>
     */
    private static function transcriptTypes(): array
    {
        return array_values(array_filter(
            ChatEventType::cases(),
            static fn (ChatEventType $type): bool => $type->isConversational() || ChatEventType::ToolCall === $type,
        ));
    }

    /**
     * What the model is sent: the turns and the tool exchanges.
     *
     * @return list<ChatEventType>
     */
    private static function wireTypes(): array
    {
        return array_values(array_filter(
            ChatEventType::cases(),
            static fn (ChatEventType $type): bool => $type->isWireMessage(),
        ));
    }
}
