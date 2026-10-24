<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Exception as DbalPdoException;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Begins SQLite transactions with `BEGIN IMMEDIATE` instead of `BEGIN`.
 *
 * ## The bug this exists to fix
 *
 * A `BEGIN` in SQLite is DEFERRED: it takes no lock at all until the first
 * statement that needs one. A read takes a shared lock, and only a later
 * `INSERT` asks to upgrade to a write lock. Two readers that both upgrade can
 * deadlock, and SQLite resolves that by refusing the second immediately with
 * `SQLITE_BUSY` — it does not wait, because waiting is exactly the deadlock.
 *
 * That is precisely the shape of every turn this app commits: read
 * `MAX(seq)` from the stream, then insert the row that continues it. Under
 * `app:run:work --parallel 2` the two processes race, and one loses its whole
 * turn to `database is locked`. Measured, not theorised: see
 * {@see \App\Stream\StreamWriter} and THE_STREAM.md §2.7(b).
 *
 * `BEGIN IMMEDIATE` takes the write lock up front, before the read. The second
 * writer then blocks at `BEGIN` — where `busy_timeout` applies and makes it
 * wait rather than fail — and when it proceeds it reads a table that already
 * contains the first writer's row, so it computes the next number correctly.
 * Five concurrent processes, one contiguous 1..5, zero failures.
 *
 * ## Why this is in the driver and not in StreamWriter
 *
 * The obvious fix — retry the insert when the constraint fires — cannot be
 * written in `StreamWriter`, and not for the reason THE_STREAM.md originally
 * gave. A constraint violation surfaces at flush, and Doctrine's
 * `UnitOfWork::commit()` responds to ANY failed flush by calling
 * `EntityManager::close()`. The retry would therefore need a closed
 * EntityManager, and the rollback has already taken the turn's checkpoint and
 * the run's claim with it. "Bounded re-read-and-retry at the flush site" is not
 * a smaller fix than this one; it is not a fix.
 *
 * Taking the lock earlier has no such problem: the transaction is still the
 * caller's, still narrow (RunEngine opens it immediately before the writes),
 * and nothing has to be unwound because nothing fails.
 *
 * ## What this does NOT fix
 *
 * Contention is not removed, only turned into a queue. A writer that holds the
 * transaction longer than `busy_timeout` still fails — correctly, because the
 * alternative is waiting forever. `PRAGMA busy_timeout` is the knob; it is set
 * on connect in {@see SqliteWalMiddleware} alongside WAL.
 *
 * Only SQLite is affected. The wrapper is installed only for `pdo_sqlite`
 * connections, so any other DATABASE_URL keeps the driver's own transaction
 * semantics.
 */
final class SqliteWriteLockConnection implements DriverConnection
{
    /**
     * How long a writer waits for the lock before giving up, in milliseconds.
     *
     * Five seconds is generous for this app — a turn's transaction is a couple
     * of inserts and an update, not a batch — while still failing loudly rather
     * than hanging a worker forever if something pathological holds the lock.
     */
    public const BUSY_TIMEOUT_MS = 5000;

    public function __construct(
        private readonly DriverConnection $connection,
    ) {
    }

    /**
     * Takes the write lock now, rather than at the first write.
     *
     * PDO understands a `BEGIN` it did not issue itself: after exec'ing this,
     * `inTransaction()` is true and `commit()`/`rollBack()` work normally.
     * Verified against PHP's pdo_sqlite, because the whole approach rests on it.
     */
    #[\Override]
    public function beginTransaction(): void
    {
        try {
            $this->connection->exec('BEGIN IMMEDIATE');
        } catch (\PDOException $exception) {
            throw DbalPdoException::new($exception);
        }
    }

    #[\Override]
    public function commit(): void
    {
        $this->connection->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    #[\Override]
    public function prepare(string $sql): Statement
    {
        return $this->connection->prepare($sql);
    }

    #[\Override]
    public function query(string $sql): Result
    {
        return $this->connection->query($sql);
    }

    #[\Override]
    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    #[\Override]
    public function exec(string $sql): int|string
    {
        return $this->connection->exec($sql);
    }

    #[\Override]
    public function lastInsertId(): int|string
    {
        return $this->connection->lastInsertId();
    }

    #[\Override]
    public function getNativeConnection(): object
    {
        return $this->connection->getNativeConnection();
    }

    #[\Override]
    public function getServerVersion(): string
    {
        return $this->connection->getServerVersion();
    }
}
