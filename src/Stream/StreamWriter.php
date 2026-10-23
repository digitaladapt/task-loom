<?php

declare(strict_types=1);

namespace App\Stream;

use App\Entity\ChatExchange;
use App\Entity\Run;
use App\Entity\StreamAuthor;
use App\Entity\StreamEvent;
use App\Entity\StreamRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one way a row enters the being's stream
 * (docs/design/THE_STREAM.md §2.1).
 *
 * ## Why this is a service and not a method on Run
 *
 * Chat's write and a turn's write have to land through the same path or
 * ordering and provenance drift into two truths again — which is precisely the
 * failure the design note is undoing, one layer down. A method on `Run` invites
 * `ChatExchange` to grow a second, slightly different one. There is no
 * aggregate here that both surfaces already share, so the shared thing has to
 * be an explicit collaborator and the docblock has to say so.
 *
 * ## Why seq is read then assigned, not generated
 *
 * `seq` has to be contiguous per stream and assigned in the same transaction as
 * the insert, or a rollback leaves a hole in the only ordering anyone trusts.
 * SQLite serialises writers anyway, so the read-then-write is not a race a
 * caller can lose *between statements*; a caller that spans two transactions
 * can still interleave, so the assignment belongs here and is done once, on the
 * row, never by the caller.
 *
 * The read is deliberately `MAX(seq)` from the table rather than a counter kept
 * anywhere else: the table is the stream, and a cached counter is one more
 * thing that can disagree with it.
 *
 * ## Why the caller does not flush
 *
 * A stream row commits with the work it describes — the turn's checkpoint, the
 * chat exchange's advance — which means the caller owns the transaction and
 * this owns only the row. Flushing here would break exactly that joining.
 */
final class StreamWriter
{
    /**
     * The highest seq this writer has issued but not yet seen committed.
     *
     * See {@see nextSeq()} — two appends inside one transaction must not be
     * handed the same number just because the first is still unflushed.
     */
    private int $issued = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Append one row and return it, positioned.
     *
     * Exactly one of `$run` and `$exchange` should be given, and both are null
     * for a row that came from neither surface (a scheduler-driven turn with no
     * run still has provenance — its author says so).
     *
     * @param list<array<string, mixed>> $toolCalls
     * @param list<array<string, mixed>> $toolResults
     */
    public function append(
        StreamAuthor $author,
        StreamRole $role,
        string $content = '',
        array $toolCalls = [],
        array $toolResults = [],
        ?Run $run = null,
        ?ChatExchange $exchange = null,
    ): StreamEvent {
        $event = new StreamEvent($author, $role);
        $event->setContent($content);
        $event->setToolCalls($toolCalls);
        $event->setToolResults($toolResults);
        $event->assignSeq($this->nextSeq());
        $this->issued = $event->getSeq();
        $event->assignToRun($run);
        $event->assignToExchange($exchange);

        $this->em->persist($event);

        return $event;
    }

    /**
     * The seq the next row will take.
     *
     * Zero for an empty stream, so the first row is 1 — contiguous from one,
     * which makes "how many rows are there" and "what was the last seq" the
     * same question.
     *
     * ## Why the persisted maximum is not the whole answer
     *
     * `MAX(seq)` sees what has been COMMITTED, and a caller may append twice in
     * one transaction — a tool turn is two rows (the assistant message that
     * announced the calls, the tool message that answers them) and the whole
     * point of the shared transaction is that they land together. Between those
     * two appends the first row is still sitting in the unit of work, invisible
     * to the query but already holding seq N. Asking the table alone would hand
     * out N a second time and the UNIQUE index would reject the turn.
     *
     * So the answer is the larger of the table's maximum and the last seq this
     * writer has handed out and not yet seen commit. Tracking it per instance is
     * enough because a stream row only ever enters through this service
     * (docs/design/THE_STREAM.md §2.1); if a second writer were ever introduced
     * the serialisation argument above — one writer per transaction — becomes
     * the thing that has to be revisited.
     */
    public function nextSeq(): int
    {
        $max = $this->em->createQuery('SELECT MAX(e.seq) FROM App\\Entity\\StreamEvent e')
            ->getSingleScalarResult();

        $persisted = null === $max ? 0 : (int) $max;

        return max($persisted, $this->issued) + 1;
    }

    /**
     * Every row from `$afterSeq` onwards, oldest first.
     *
     * The tail read the design calls for: a window is a view onto this, not a
     * copy of it, so the window asks for what it has not seen rather than
     * carrying what it has.
     *
     * @return list<StreamEvent>
     */
    public function tail(int $afterSeq = 0, int $limit = 500): array
    {
        /** @var list<StreamEvent> $rows */
        $rows = $this->em->createQuery(
            'SELECT e FROM App\\Entity\\StreamEvent e WHERE e.seq > :after ORDER BY e.seq ASC'
        )
            ->setParameter('after', $afterSeq)
            ->setMaxResults($limit)
            ->getResult();

        return $rows;
    }

    /**
     * The newest `$count` rows, oldest first.
     *
     * This is the window's primary read: it wants the recent end of the stream,
     * and asking for it backwards then reversing keeps the query on the index
     * instead of scanning forward from the beginning of a table that never ends.
     *
     * @return list<StreamEvent>
     */
    public function latest(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        /** @var list<StreamEvent> $rows */
        $rows = $this->em->createQuery(
            'SELECT e FROM App\\Entity\\StreamEvent e ORDER BY e.seq DESC'
        )
            ->setMaxResults($count)
            ->getResult();

        return array_reverse($rows);
    }
}
