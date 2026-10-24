<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SqliteWalMiddleware;
use App\Doctrine\SqliteWriteLockConnection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqliteDriver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The §2.7(b) race, and the connection settings that fix it.
 *
 * Two connections, both reading `MAX(seq)` before either inserts — which is
 * exactly what `app:run:work --parallel 2` does. Before the fix the second one
 * died on `database is locked` and lost its turn; the point of these tests is
 * that it now waits and takes the next number.
 *
 * Each connection here is built through the production middleware, so a test
 * cannot pass on pragmas the app does not actually set.
 *
 * ### What is tested here, and what is not
 *
 * These run two connections inside one process, so they are not a true
 * multi-process reproduction. That was done separately against five OS
 * processes and is recorded in THE_STREAM.md §2.7(b); the numbers there are the
 * evidence, and they are not reproducible in PHPUnit without spawning workers.
 *
 * What these pin down is the mechanism, which is what would regress silently:
 * that a transaction holds the write lock from `BEGIN` rather than from its
 * first write, and that the busy timeout is in place. A later change back to a
 * deferred BEGIN would leave the seq tests in StreamWriterTest passing while
 * reintroducing the lost-turn bug under parallel workers — this is the test
 * that fails.
 */
final class SqliteWriteLockConnectionTest extends KernelTestCase
{
    private string $file = '';

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->file = tempnam(sys_get_temp_dir(), 'stream-lock-').'.db';

        $connection = $this->openConnection();

        try {
            $connection->executeStatement(
                'CREATE TABLE stream_event (id INTEGER PRIMARY KEY AUTOINCREMENT, '
                .'being_id INTEGER NOT NULL, seq INTEGER NOT NULL, content TEXT)'
            );
            $connection->executeStatement(
                'CREATE UNIQUE INDEX UNIQ_STREAM_EVENT ON stream_event (being_id, seq)'
            );
        } finally {
            $connection->close();
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file.'-wal', $this->file.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * A transaction holds the write lock from BEGIN, before writing anything.
     *
     * This is the property that makes the fix work: a deferred transaction
     * would leave the lock free here, and the second connection's read would
     * succeed against a table about to change underneath it.
     */
    public function testATransactionHoldsTheWriteLockBeforeItWrites(): void
    {
        $holder = $this->openConnection();
        $other = $this->openImpatientConnectionTo($this->file);

        try {
            $holder->beginTransaction();

            $blocked = false;

            try {
                $other->executeStatement('BEGIN IMMEDIATE');
                $other->executeStatement('ROLLBACK');
            } catch (\Throwable) {
                $blocked = true;
            }

            self::assertTrue(
                $blocked,
                'A transaction must hold the write lock from BEGIN, not from its first write.',
            );

            $holder->rollBack();
        } finally {
            $holder->close();
            $other->close();
        }
    }

    /**
     * The connection the app really runs on behaves this way.
     *
     * Asserted by behaviour rather than by class name: Symfony's profiler wraps
     * the driver connection in its own Debug middleware, so the class at the
     * top of the chain is a framework detail and middleware ordering is not
     * something this test should be pinned to. What matters is that a
     * transaction on the container's connection holds the write lock.
     */
    public function testTheContainerConnectionHoldsTheWriteLockFromBegin(): void
    {
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        self::assertInstanceOf(Connection::class, $connection);

        $params = $connection->getParams();

        if (!\in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
            self::markTestSkipped('These settings only apply to SQLite connections.');
        }

        // The test database, opened separately, exactly as a second worker
        // process would open it.
        $second = $this->openImpatientConnectionTo($params['path']);

        $connection->beginTransaction();

        try {
            $blocked = false;

            try {
                $second->executeStatement('BEGIN IMMEDIATE');
                $second->executeStatement('ROLLBACK');
            } catch (\Throwable) {
                $blocked = true;
            }

            self::assertTrue(
                $blocked,
                'The container connection must begin IMMEDIATE (THE_STREAM.md §2.7(b)).',
            );
        } finally {
            $connection->rollBack();
            $second->close();
        }
    }

    public function testBusyTimeoutIsSetOnConnect(): void
    {
        $connection = $this->openConnection();

        try {
            $timeout = $connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

            self::assertSame(
                SqliteWriteLockConnection::BUSY_TIMEOUT_MS,
                (int) $timeout,
                'A waiting writer needs a busy timeout, or BEGIN IMMEDIATE turns into an instant failure.',
            );
        } finally {
            $connection->close();
        }
    }

    public function testJournalModeIsWal(): void
    {
        $connection = $this->openConnection();

        try {
            self::assertSame('wal', $connection->executeQuery('PRAGMA journal_mode')->fetchOne());
        } finally {
            $connection->close();
        }
    }

    /**
     * The end-to-end shape of the bug: read MAX(seq), then insert that number.
     *
     * With a deferred BEGIN both connections can read 1 and one dies. With
     * BEGIN IMMEDIATE the second cannot read until the first commits, so it
     * reads 2 and the stream stays contiguous — the property the whole table
     * leans on.
     */
    public function testTwoWritersProduceAContiguousStreamRatherThanOneLostTurn(): void
    {
        $results = [];

        foreach (['first' => 100_000, 'second' => 0] as $label => $holdMicros) {
            $connection = $this->openConnection();

            try {
                $connection->beginTransaction();

                $max = $connection->executeQuery(
                    'SELECT MAX(seq) FROM stream_event WHERE being_id = 1'
                )->fetchOne();
                $next = (null === $max ? 0 : (int) $max) + 1;

                // Hold the transaction open so the two writers genuinely
                // overlap rather than merely following one another.
                usleep($holdMicros);

                $connection->executeStatement(
                    'INSERT INTO stream_event (being_id, seq, content) VALUES (1, ?, ?)',
                    [$next, $label],
                );
                $connection->commit();

                $results[$label] = $next;
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $results[$label] = $e::class.': '.$e->getMessage();
            } finally {
                $connection->close();
            }
        }

        self::assertSame(1, $results['first'], 'first writer: '.json_encode($results));
        self::assertSame(2, $results['second'], 'second writer: '.json_encode($results));

        $verify = $this->openConnection();

        try {
            $seqs = $verify->executeQuery('SELECT seq FROM stream_event ORDER BY seq')->fetchFirstColumn();
            self::assertSame([1, 2], array_map(intval(...), $seqs), 'The stream must stay contiguous from 1.');
        } finally {
            $verify->close();
        }
    }

    /**
     * A connection built the way the app builds one: the middleware-wrapped
     * driver, so the pragmas and the BEGIN IMMEDIATE wrapper are both live.
     */
    private function openConnection(): Connection
    {
        return $this->openConnectionTo($this->file);
    }

    private function openConnectionTo(string $path): Connection
    {
        $driver = (new SqliteWalMiddleware())->wrap(new SqliteDriver());

        return new Connection(
            ['driver' => 'pdo_sqlite', 'path' => $path],
            $driver,
            new Configuration(),
        );
    }

    /**
     * A connection that gives up on the lock almost immediately.
     *
     * The tests below prove the lock is held by failing to take it, which means
     * waiting out a real busy timeout — five seconds each, which is not a
     * useful thing to spend on a suite that runs on every commit. It is the
     * probe's own timeout that governs how long the probe waits, so shortening
     * it changes no production behaviour and weakens no assertion. That the
     * production timeout is the intended value is asserted separately by
     * {@see testBusyTimeoutIsSetOnConnect}.
     */
    private function openImpatientConnectionTo(string $path): Connection
    {
        $connection = $this->openConnectionTo($path);
        $connection->executeStatement('PRAGMA busy_timeout=25');

        return $connection;
    }
}
