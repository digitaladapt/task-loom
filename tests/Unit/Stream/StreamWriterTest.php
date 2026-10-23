<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stream;

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

        $first = $writer->append(StreamAuthor::Being, StreamRole::Assistant, 'calling a tool', [['id' => 'call_1']]);
        $second = $writer->append(StreamAuthor::Being, StreamRole::Tool, '', [], [['tool_call_id' => 'call_1', 'content' => 'ok']]);

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

        self::assertSame(1, $writer->append(StreamAuthor::Operator, StreamRole::User, 'hello')->getSeq());
    }

    /**
     * A writer joining an existing stream continues it rather than restarting
     * at one — every new request builds a new writer, so this is the normal
     * path after a restart.
     */
    public function testSeqContinuesFromThePersistedMaximum(): void
    {
        $writer = new StreamWriter($this->entityManagerReturning(41));

        self::assertSame(42, $writer->append(StreamAuthor::Being, StreamRole::Assistant, 'next')->getSeq());
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
        $writer->append(StreamAuthor::Being, StreamRole::Assistant, 'took seq 1');

        self::assertSame(91, $writer->append(StreamAuthor::Being, StreamRole::Assistant, 'took 91')->getSeq());
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

        self::assertSame([], $writer->latest(0));
        self::assertSame([], $writer->latest(-5));
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
        $query->method('getSingleScalarResult')->willReturn($max);

        return $query;
    }
}
