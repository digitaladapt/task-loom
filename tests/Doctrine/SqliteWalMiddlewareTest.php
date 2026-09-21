<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SqliteWalMiddlewareTest extends KernelTestCase
{
    public function testSqliteConnectionUsesWalJournalMode(): void
    {
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        if (!$connection instanceof Connection) {
            self::fail('doctrine.dbal.default_connection is not a DBAL Connection.');
        }

        $params = $connection->getParams();

        if (!\in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
            self::markTestSkipped('WAL pragma only applies to SQLite connections.');
        }

        $mode = $connection->executeQuery('PRAGMA journal_mode')->fetchOne();

        self::assertSame('wal', $mode, 'SQLite connection should run in WAL journal mode (GUIDING-LIGHT §8.6).');
    }
}
