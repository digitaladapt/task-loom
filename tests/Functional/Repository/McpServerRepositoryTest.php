<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Repository\McpServerRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises McpServerRepository::findSyncable(). The suite runs with
 * failOnDeprecation=true, so this test also guards against the deprecated
 * string form of QueryBuilder::orderBy() ('ASC') sneaking back in — the
 * SortDirection::Ascending enum is required.
 */
final class McpServerRepositoryTest extends KernelTestCase
{
    private McpServerRepository $servers; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        // Fresh rows per test.
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $em->flush();
        $em->clear();

        $this->servers = static::getContainer()->get(McpServerRepository::class);
    }

    private function newServer(string $name, bool $enabled = true): McpServer
    {
        $server = new McpServer($name, 'http://example.invalid/mcp', ServerProtocol::Mcp);
        $server->setEnabled($enabled);
        $this->servers->save($server);

        return $server;
    }

    public function testFindSyncableReturnsOnlyEnabledServersOrderedByName(): void
    {
        $beta = $this->newServer('beta');
        $this->newServer('gamma', enabled: false);
        $alpha = $this->newServer('alpha');

        $syncable = $this->servers->findSyncable();

        self::assertSame([$alpha->getId(), $beta->getId()], array_map(
            static fn (McpServer $server) => $server->getId(),
            $syncable,
        ));
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testFindSyncableWithNoServersReturnsEmptyResult(): void
    {
        $this->servers->findSyncable();
    }
}
