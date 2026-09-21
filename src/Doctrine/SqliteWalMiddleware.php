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
 * Enables WAL journaling on SQLite connections.
 *
 * SPEC.md and bin/backup-db.sh both assume WAL (snapshot backup while writes
 * are in flight), but nothing ever set the pragma — SQLite stayed in its
 * default rollback-journal mode. GUIDING-LIGHT §8.6: "Decide: WAL mode on."
 *
 * Registered via the doctrine.middleware tag; applies only when the resolved
 * connection actually uses pdo_sqlite, so it is a no-op for any non-SQLite
 * DATABASE_URL.
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

        if (($params['driver'] ?? '') === 'pdo_sqlite') {
            // WAL persists in the database file; the pragma is idempotent and
            // safe to run on every connect.
            $connection->exec('PRAGMA journal_mode=WAL');
        }

        return $connection;
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
