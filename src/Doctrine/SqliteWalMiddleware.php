<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Sets the SQLite connection up the way this app needs it: WAL, a busy timeout,
 * and a transaction that takes its write lock at BEGIN.
 *
 * SPEC.md and bin/backup-db.sh both assume WAL (snapshot backup while writes
 * are in flight), but nothing ever set the pragma — SQLite stayed in its
 * default rollback-journal mode. GUIDING-LIGHT §8.6: "Decide: WAL mode on."
 *
 * The other two settings arrived with THE_STREAM.md §2.7(b): a turn reads
 * `MAX(seq)` from the stream and then inserts the row that continues it, which
 * a DEFERRED transaction cannot do safely from two processes. Together the
 * pragma and the wrapper turn "one worker loses its turn to `database is
 * locked`" into "the second worker waits its turn". See
 * {@see SqliteWriteLockConnection} for the measurements.
 *
 * Registered via the doctrine.middleware tag; applies only when the resolved
 * connection actually uses pdo_sqlite, so it is a no-op for any non-SQLite
 * DATABASE_URL — including the busy timeout, which other engines do not want
 * in this form.
 */
final class SqliteWalMiddleware implements Middleware
{
    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new SqliteWalDriver($driver);
    }
}

/**
 * Wraps the underlying driver; on connect, flips SQLite into WAL mode.
 */
final class SqliteWalDriver implements Driver
{
    public function __construct(
        private readonly Driver $driver,
    ) {
    }

    #[\Override]
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): Connection {
        $connection = $this->driver->connect($params);

        if (($params['driver'] ?? '') !== 'pdo_sqlite') {
            return $connection;
        }

        // WAL persists in the database file; the pragma is idempotent and
        // safe to run on every connect.
        $connection->exec('PRAGMA journal_mode=WAL');

        // A writer waiting on another writer's lock gives up after this long,
        // instead of failing instantly. This is only useful because
        // {@see SqliteWriteLockConnection} takes the lock at BEGIN: a deferred
        // transaction does not wait at all, it dies on the lock upgrade
        // (THE_STREAM.md §2.7(b)).
        $connection->exec('PRAGMA busy_timeout='.SqliteWriteLockConnection::BUSY_TIMEOUT_MS);

        return new SqliteWriteLockConnection($connection);
    }

    #[\Override]
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return $this->driver->getDatabasePlatform($versionProvider);
    }

    #[\Override]
    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->driver->getExceptionConverter();
    }
}
