<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stream;

use App\Entity\Being;
use App\Entity\StreamAuthor;
use App\Entity\StreamEvent;
use App\Entity\StreamRole;
use App\Stream\StreamWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * StreamWriter: the single append path (docs/design/THE_STREAM.md §2.1).
 *
 * The case worth pinning is the one the docblock argues about: a tool turn is
 * *two* appends inside one transaction, and between them the first row is in
 * the unit of work, invisible to `MAX(seq)`, yet already holding a number.
 * Asking the table alone would hand out the same seq twice and the UNIQUE
 * index would reject the turn — so the writer's own uncommitted watermark has
 * to be what saves it.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreamWriterTest extends TestCase
{
    /**
     * The regression this test exists for: two appends before any flush must
     * not collide.
     */
    public function testSecondAppendInSameTransactionGetsTheNextSeq(): void
    {
        // The table is empty and stays empty (nothing has flushed).
        $writer = new StreamWriter($this->entityManagerReturning(null));
        $being = $this->being();

        $first = $writer->append($being, StreamAuthor::Being, StreamRole::Assistant, 'calling a tool', [['id' => 'call_1']]);
        $second = $writer->append($being, StreamAuthor::Being, StreamRole::Tool, '', [], [['tool_call_id' => 'call_1', 'content' => 'ok']]);

        self::assertSame(1, $first->getSeq());
        self::assertSame(2, $second->getSeq(), 'the uncommitted first row must still occupy seq 1');
    }

    /**
     * The first row of an empty stream is 1, not 0: "how many rows" and "what
     * was the last seq" are then the same question.
     */
    public function testFirstRowOfEmptyStreamIsOne(): void
    {
        $writer = new StreamWriter($this->entityManagerReturning(null));

        self::assertSame(1, $writer->append($this->being(), StreamAuthor::Operator, StreamRole::User, 'hello')->getSeq());
    }

    /**
     * A writer joining an existing stream continues it rather than restarting
     * at one — every new request builds a new writer, so this is the normal
     * path after a restart.
     */
    public function testSeqContinuesFromThePersistedMaximum(): void
    {
        $writer = new StreamWriter($this->entityManagerReturning(41));

        self::assertSame(42, $writer->append($this->being(), StreamAuthor::Being, StreamRole::Assistant, 'next')->getSeq());
    }

    /**
     * Two beings are two streams: the same table, disjoint number lines.
     *
     * This is the whole reason `seq` is scoped (THE_STREAM.md §2.2) — a shared
     * counter would let one being's turn dictate the other's ordering.
     */
    public function testEachBeingNumbersItsOwnStream(): void
    {
        $writer = new StreamWriter($this->entityManagerReturning(null));

        $first = $writer->append($this->being(), StreamAuthor::Being, StreamRole::Assistant, 'mine')->getSeq();
        $other = $writer->append($this->being(), StreamAuthor::Being, StreamRole::Assistant, 'theirs')->getSeq();

        self::assertSame(1, $first);
        self::assertSame(1, $other, 'a second being starts its own stream at one');
    }

    /**
     * The watermark wins only when it is *ahead* of the table. Once other rows
     * have committed beyond it (a longer-lived sibling writer, a restore), the
     * table is the truth — otherwise this writer would reissue numbers.
     */
    public function testPersistedMaximumWinsWhenAheadOfTheWatermark(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))
            ->method('createQuery')
            ->willReturnOnConsecutiveCalls(
                $this->queryReturning(null),
                $this->queryReturning(90),
            );
        $em->method('persist');

        $writer = new StreamWriter($em);
        $being = $this->being();
        $writer->append($being, StreamAuthor::Being, StreamRole::Assistant, 'took seq 1');

        self::assertSame(91, $writer->append($being, StreamAuthor::Being, StreamRole::Assistant, 'took 91')->getSeq());
    }

    /**
     * A watermark the table has fallen behind is stale, not authoritative.
     *
     * The in-process watermark exists because an unflushed row is invisible to
     * `MAX(seq)` while already holding a number. But when the table is *behind*
     * the watermark, the unflushed row is not invisible — it is gone, because
     * the fixture truncated the table or the transaction rolled back. Trusting
     * `max()` there numbers the next append from a phantom and leaves a hole in
     * a stream that is supposed to be contiguous from one.
     *
     * Same being both times on purpose: the cache is keyed by being, so a fresh
     * id per test — as {@see Being()} otherwise does — would hide the bug rather
     * than exercise it.
     */
    public function testStaleWatermarkYieldsToTheTableAfterATruncation(): void
    {
        $being = $this->being();

        // First writer sees an empty table and takes seq 1; the row stays
        // unflushed, so the process remembers "1 is spoken for".
        $before = new StreamWriter($this->entityManagerReturning(null));
        self::assertSame(1, $before->append($being, StreamAuthor::Being, StreamRole::Assistant, 'x')->getSeq());

        // The row is then destroyed without being committed — exactly what a
        // truncating fixture does. The table is empty again, but the static
        // cache still claims 1 is taken.
        $after = new StreamWriter($this->entityManagerReturning(null));

        self::assertSame(
            1,
            $after->append($being, StreamAuthor::Being, StreamRole::Assistant, 'y')->getSeq(),
            'a watermark ahead of the committed table describes a row that no longer exists',
        );
    }

    /**
     * Provenance and payload land on the row, and nothing is flushed: the
     * caller owns the transaction that commits this row with the work it
     * describes.
     */
    public function testAppendPersistsWithoutFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('createQuery')->willReturn($this->queryReturning(null));
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(StreamEvent::class));
        $em->expects(self::never())->method('flush');

        $writer = new StreamWriter($em);
        $row = $writer->append(
            $this->being(),
            StreamAuthor::Scheduler,
            StreamRole::Assistant,
            'a scheduled turn',
            [['id' => 'call_9']],
            [['tool_call_id' => 'call_9', 'content' => 'done']],
        );

        self::assertSame(StreamAuthor::Scheduler, $row->getAuthor());
        self::assertSame(StreamRole::Assistant, $row->getRole());
        self::assertSame('a scheduled turn', $row->getContent());
        self::assertCount(1, $row->getToolCalls());
        self::assertCount(1, $row->getToolResults());
    }

    public function testTailAndLatestClampTheCount(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQuery');

        $writer = new StreamWriter($em);

        self::assertSame([], $writer->latest($this->being(), 0));
        self::assertSame([], $writer->latest($this->being(), -5));
    }

    /**
     * A being with an id, so the writer's in-process watermark keys on something.
     *
     * The stream is per being (THE_STREAM.md §2.2): `append()` will not guess
     * one, because a row whose scope was inferred is a row whose scope is
     * unknowable later.
     *
     * A distinct id per call by default: the watermark is keyed by connection
     * *and* being, so reusing one is not itself dangerous — but a test that
     * shares an entity manager with another and reuses an id is testing the
     * watermark's memory instead of the behaviour it means to pin.
     *
     * {@see testStaleWatermarkYieldsToTheTableAfterATruncation()} reuses one on
     * purpose, because there the memory is the thing under test.
     */
    private function being(): Being
    {
        static $next = 1000;

        $being = new Being();
        $ref = new \ReflectionProperty(Being::class, 'id');
        $ref->setValue($being, $next++);

        return $being;
    }

    private function entityManagerReturning(?int $max): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($this->queryReturning($max));
        $em->method('persist');

        return $em;
    }

    private function queryReturning(?int $max): Query
    {
        $query = $this->createMock(Query::class);

        // Fluent stubs: `setParameter()` is called before the terminal read, and
        // an unstubbed `willReturn` hands back a fresh stub, so the scalar would
        // never leave the original mock.
        $query->method('setParameter')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn($max);

        return $query;
    }
}
