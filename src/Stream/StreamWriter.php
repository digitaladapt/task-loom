<?php

declare(strict_types=1);

namespace App\Stream;

use App\Entity\Being;
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
 * The assignment belongs here and is done once, on the row, never by the
 * caller.
 *
 * The read is deliberately `MAX(seq)` from the table rather than a counter kept
 * anywhere else: the table is the stream, and a cached counter is one more
 * thing that can disagree with it.
 *
 * ## KNOWN RACE: two writers can be handed the same seq (THE_STREAM.md §2.7)
 *
 * This docblock used to claim that "SQLite serialises writers anyway, so the
 * read-then-write is not a race a caller can lose between statements". **That
 * is false, and it was verified false** against SQLite 3.46 with two
 * connections doing exactly what this class does: both `SELECT MAX(seq)` and
 * get the same answer, both `INSERT` that number, and the second one dies on
 * `UNIQUE_STREAM_EVENT_SEQ`. SQLite serialises *transactions*, not two
 * statements issued from two processes — and `app:run:work --parallel 2` is
 * two processes.
 *
 * It cannot be fixed in here, and that is the point worth recording: the
 * collision is only detected when the row is flushed, and this class
 * deliberately does not own the flush — the caller does, so the row commits
 * with the work it describes ({@see append()}). The re-read-and-retry has to
 * live wherever the flush lives. Until it does, a parallel fleet can lose a
 * turn to a constraint violation.
 *
 * Sequence numbering `nextSeq()` via `MAX(seq)` is still the right *read*; what
 * is missing is a write path that survives losing the race for the number.
 *
 * ## Why the caller does not flush
 *
 * A stream row commits with the work it describes — the turn's checkpoint, the
 * chat exchange's advance — which means the caller owns the transaction and
 * this owns only the row. Flushing here would break exactly that joining.
 *
 * ## One stream per being (THE_STREAM.md §2.2)
 *
 * Every read and write is scoped to a {@see Being}, and the sequencing is
 * therefore per being: `seq` restarts at 1 for each one and the UNIQUE index is
 * `(being_id, seq)`, not `seq` alone. A single global ordering could not survive
 * a second being — two independent streams interleaved into one number line is
 * not an ordering, it is a coincidence.
 *
 * The class is *not* a singleton per being. Several tasks can still be due in
 * one scheduler tick, and while §2.5 makes a being's turns sequential, the
 * component that enforces that (`send` at the nexus) does not exist yet. So the
 * writer has to be correct when two instances append at once, which is what
 * {@see $issued} and the per-being query scope below take care of.
 */
final class StreamWriter
{
    /**
     * The highest seq issued for a being, held against the writer's connection.
     *
     * Keyed by the `EntityManagerInterface` we were constructed with, then by
     * being id. That is not a detail: it is what makes the number belong to the
     * right unit of work.
     *
     * A tool turn is two appends in one transaction, and between them the first
     * row is in the unit of work, invisible to `MAX(seq)` while already holding
     * a number — {@see nextSeq()} has to remember it or the turn collides with
     * itself.
     *
     * The memory must not outlive the unit of work, because the next transaction
     * is a different writer against a different connection, and what that one
     * previously issued either committed — and is now visible to the query — or
     * was discarded, in which case its number is free again. Remembering it
     * anyway is how a rolled-back or truncated row returns as a phantom and
     * leaves a hole in a stream that is contiguous from one.
     *
     * Keying on the entity manager gives exactly that lifetime where a request
     * owns its connection, which is the production shape here. It also survives
     * two writers sharing one manager inside a single transaction (a scheduler
     * tick building two children, a test calling the service twice): they share
     * the connection, so they share the blindness, so they must share the
     * memory. This replaced an earlier `static array` keyed by being alone,
     * which made the memory outlive the transaction and outlive the table.
     *
     * A `WeakMap`, not an array, so a retired manager is collectable and its
     * entry goes with it — a long-running worker must not accumulate one entry
     * per request forever.
     *
     * Known limit: if a connection outlives the transaction (some pools reset
     * rather than rebuild), the memory can still be stale. That case is not
     * reachable here, where the manager is per message, and fixing it properly
     * needs a post-flush/post-rollback hook this class does not own —
     * {@see append()} explains why it deliberately owns no flush.
     *
     * @var \WeakMap<EntityManagerInterface, array<int, int>>|null
     */
    private static ?\WeakMap $issued = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The highest seq this connection has issued for a being, or zero.
     */
    private function lastIssued(int $beingId): int
    {
        self::$issued ??= new \WeakMap();

        return (self::$issued[$this->em] ?? [])[$beingId] ?? 0;
    }

    /**
     * Record that a being's stream has been handed this seq on this connection.
     */
    private function rememberIssued(int $beingId, int $seq): void
    {
        self::$issued ??= new \WeakMap();

        $byBeing = self::$issued[$this->em] ?? [];
        $byBeing[$beingId] = $seq;
        self::$issued[$this->em] = $byBeing;
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
        Being $being,
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
        $event->assignToBeing($being);
        $event->assignSeq($this->nextSeq($being));
        $event->assignToRun($run);
        $event->assignToExchange($exchange);

        if (null !== $being->getId()) {
            $this->rememberIssued($being->getId(), $event->getSeq());
        }

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
     * So the answer is the larger of the table's maximum for this being and the
     * last seq any writer in this process handed out for it and has not yet seen
     * commit.
     *
     * ## Why the maximum is scoped to the being
     *
     * A `MAX(seq)` across every being would hand being B a number derived from
     * being A's stream, leaving B's own sequence full of holes — and "contiguous
     * from one" is the property the whole table leans on. See §2.2: there is one
     * stream per being, so there is one number line per being.
     */
    public function nextSeq(Being $being): int
    {
        $max = $this->em->createQuery(
            'SELECT MAX(e.seq) FROM App\\Entity\\StreamEvent e WHERE e.being = :being'
        )
            ->setParameter('being', $being)
            ->getSingleScalarResult();

        $persisted = null === $max ? 0 : (int) $max;
        $issued = null === $being->getId() ? 0 : $this->lastIssued($being->getId());

        return max($persisted, $issued) + 1;
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
    public function tail(Being $being, int $afterSeq = 0, int $limit = 500): array
    {
        /** @var list<StreamEvent> $rows */
        $rows = $this->em->createQuery(
            'SELECT e FROM App\\Entity\\StreamEvent e
             WHERE e.being = :being AND e.seq > :after ORDER BY e.seq ASC'
        )
            ->setParameter('being', $being)
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
    public function latest(Being $being, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        /** @var list<StreamEvent> $rows */
        $rows = $this->em->createQuery(
            'SELECT e FROM App\\Entity\\StreamEvent e
             WHERE e.being = :being ORDER BY e.seq DESC'
        )
            ->setParameter('being', $being)
            ->setMaxResults($count)
            ->getResult();

        return array_reverse($rows);
    }
}
