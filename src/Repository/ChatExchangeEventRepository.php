<?php

declare(strict_types=1);

namespace App\Repository;

use App\Chat\ChatWireWindow;
use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ChatExchangeEvent queries: the transcript read and the wire window.
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
 * `findTranscript()` is what the *page* shows; `findWireWindow()` is what the
 * *model* is sent. They differ by the tool rows, and conflating them is a bug
 * in either direction: the page would show tool plumbing as conversation, or
 * the model would be re-asked a question whose answer it already has.
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
     * The wire rows the model may be shown: the newest whole turns, plus an
     * exact count of the older turns the read left behind (SPEC §15.9).
     *
     * A conversation with tools has to send the model the calls it made and
     * the results it got — plus the assistant `tool_calls` message and the
     * `tool` result messages the endpoint requires as a matched pair. A
     * *turn* here is the smallest slice of that shape that travels whole: a
     * conversational row, or a `tool_calls` row together with its result
     * rows. The read is cut at a turn boundary — chosen from the newest
     * `$maxTurns` turn-starting rows — so the oldest returned row is always a
     * conversational or `tool_calls` row and **no returned fragment can begin
     * mid-round**. That property is what lets the context window count what
     * it was handed exactly instead of guessing at a partial round, and it is
     * why this read exists at all: an unbounded read would pull a
     * years-deep transcript into memory for every message.
     *
     * The older remainder is reported by kind, not as a bare total, so the
     * trim a request records can say how many *turns* and how many *tool
     * rounds* left the model's view — the same nouns the run ledger's
     * `context_trim` speaks.
     *
     * **The returned rows always begin at a turn boundary.** That holds by
     * construction — the boundary *is* a turn-starting row, so the first row
     * fetched is it — and the defensive drop below keeps the promise true if
     * that construction ever changes. The rows it would drop belong to a
     * round that started before the boundary and is already counted in
     * `olderRounds`, so dropping them moves no count: the promise is "whole
     * turns only", and the totals obey it.
     */
    public function findWireWindow(Chat $chat, int $maxTurns = 100): ChatWireWindow
    {
        $maxTurns = max(1, $maxTurns);

        /** @var list<int|string> $boundaries the ids of the newest turn-starting rows */
        $boundaries = $this->createQueryBuilder('e')
            ->select('e.id')
            ->join('e.exchange', 'x')
            ->where('x.chat = :chat')
            ->andWhere('e.type IN (:types)')
            ->setParameter('chat', $chat)
            ->setParameter('types', self::turnStartTypes())
            ->orderBy('e.id', \SortDirection::Descending)
            ->setMaxResults($maxTurns)
            ->getQuery()->getSingleColumnResult();

        if ([] === $boundaries) {
            return new ChatWireWindow([]);
        }

        $boundary = (int) min(array_map(intval(...), $boundaries));

        /** @var list<ChatExchangeEvent> $rows */
        $rows = $this->createQueryBuilder('e')
            ->join('e.exchange', 'x')
            ->where('x.chat = :chat')
            ->andWhere('e.type IN (:types)')
            ->andWhere('e.id >= :boundary')
            ->setParameter('chat', $chat)
            ->setParameter('types', self::wireTypes())
            ->setParameter('boundary', $boundary)
            ->orderBy('e.id', \SortDirection::Ascending)
            ->getQuery()->getResult();

        // Whole turns only, kept true defensively: the boundary is a
        // turn-starting row, so this should never fire — and if it ever does,
        // the round owning these rows is already counted among
        // `olderRounds`, so nothing leaves unrecorded.
        while ([] !== $rows && !$rows[0]->getType()->startsWireUnit()) {
            array_shift($rows);
        }

        // The cap only bites when there was at least one turn-start to leave
        // behind; otherwise the read covered every turn and nothing is
        // claimed that did not happen.
        $olderTurns = 0;
        $olderRounds = 0;
        if (\count($boundaries) >= $maxTurns) {
            $olderTurns = $this->countOlderThan($chat, self::conversationalTypes(), $boundary);
            $olderRounds = $this->countOlderThan($chat, [ChatEventType::ToolCall], $boundary);
        }

        return new ChatWireWindow($rows, $olderTurns, $olderRounds);
    }

    /**
     * The most recent `context_trim` row of a conversation, or null when the
     * model's window has never had to shed anything (SPEC §15.9).
     *
     * The surface reads this to tell the human, in words, that the model can
     * no longer see the beginning of the conversation — the chat page has no
     * ledger timeline to render the row into, so the fact is translated into
     * a sentence rather than left in the ledger.
     */
    public function findLatestContextTrim(Chat $chat): ?ChatExchangeEvent
    {
        return $this->createQueryBuilder('e')
            ->join('e.exchange', 'x')
            ->where('x.chat = :chat')
            ->andWhere('e.type = :type')
            ->setParameter('chat', $chat)
            ->setParameter('type', ChatEventType::ContextTrim)
            ->orderBy('e.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
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

    /** @param list<ChatEventType> $types */
    private function countOlderThan(Chat $chat, array $types, int $boundary): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.exchange', 'x')
            ->where('x.chat = :chat')
            ->andWhere('e.type IN (:types)')
            ->andWhere('e.id < :boundary')
            ->setParameter('chat', $chat)
            ->setParameter('types', $types)
            ->setParameter('boundary', $boundary)
            ->getQuery()->getSingleScalarResult();
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
            static fn (ChatEventType $type): bool => $type->startsWireUnit(),
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

    /**
     * The rows a turn can *start* at: a conversational row, or a `tool_calls`
     * row (whose result rows belong to it).
     *
     * @return list<ChatEventType>
     */
    private static function turnStartTypes(): array
    {
        return array_values(array_filter(
            ChatEventType::cases(),
            static fn (ChatEventType $type): bool => $type->startsWireUnit(),
        ));
    }

    /**
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
