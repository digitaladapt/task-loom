<?php

declare(strict_types=1);

namespace App\Tests\Functional\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Repository\McpServerRepository;
use App\Repository\ToolRepository;
use App\Toolbox\CatalogSynchronizer;
use App\Toolbox\DiscoveredTool;
use App\Toolbox\ServerReaderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class CatalogSynchronizerTest extends KernelTestCase
{
    private McpServerRepository $servers; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolRepository $tools; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private CatalogSynchronizer $sync; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private FakeServerReader $reader; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        // Fresh catalog per test (the sync flow mutates server/tool rows).
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->createQuery('DELETE FROM App\\Entity\\Tool')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\McpServer')->execute();
        $em->flush();
        $em->clear();

        $this->servers = static::getContainer()->get(McpServerRepository::class);
        $this->tools = static::getContainer()->get(ToolRepository::class);

        $this->reader = new FakeServerReader();
        $registry = new ServerReaderRegistry([
            ServerProtocol::Mcp->value => $this->reader,
            ServerProtocol::OpenApi->value => $this->reader,
        ]);

        $this->sync = new CatalogSynchronizer(
            $this->servers,
            $this->tools,
            static::getContainer()->get(EntityManagerInterface::class),
            $registry,
            new \Psr\Log\NullLogger(),
            new MockClock('2026-01-01 09:00:00'),
        );
    }

    private function newServer(string $name): McpServer
    {
        $server = new McpServer($name, 'http://example.invalid/mcp', ServerProtocol::Mcp);
        $this->servers->save($server);

        return $server;
    }

    private function discovered(string $name, string $description): DiscoveredTool
    {
        return new DiscoveredTool($name, $description, ['type' => 'object']);
    }

    public function testFreshServerDiscoveryCreatesToolsWithDefaultTags(): void
    {
        $server = $this->newServer('weather');
        $this->reader->next = [$this->discovered('get_current', 'Current weather')];

        $result = $this->sync->syncServer($server);

        self::assertTrue($result->ok);
        self::assertSame(1, $result->created);
        self::assertSame(0, $result->updated);

        $tool = $this->tools->findOneBy(['name' => 'get_current']);
        self::assertNotNull($tool);
        self::assertSame(['weather'], $tool->getTags());
        self::assertSame('Current weather', $tool->getDescription());
        self::assertSame('ok', $server->getLastSyncStatus());
    }

    public function testReSyncWithoutChangesIsIdempotent(): void
    {
        $server = $this->newServer('weather');
        $this->reader->next = [$this->discovered('get_current', 'Current weather')];
        $this->sync->syncServer($server);

        $second = $this->sync->syncServer($server);

        self::assertSame(0, $second->created);
        self::assertSame(0, $second->updated);
        self::assertSame(0, $second->drifted);
    }

    public function testChangedDescriptionUpdatesDiscoveredTool(): void
    {
        $server = $this->newServer('weather');
        $this->reader->next = [$this->discovered('get_current', 'v1 description')];
        $this->sync->syncServer($server);

        $this->reader->next = [$this->discovered('get_current', 'v2 description')];
        $second = $this->sync->syncServer($server);

        self::assertSame(0, $second->created);
        self::assertSame(1, $second->updated);
        self::assertSame(1, $second->drifted);

        $tool = $this->tools->findOneBy(['name' => 'get_current']);
        self::assertSame('v2 description', $tool->getDescription());
    }

    public function testDownServerKeepsKnownTools(): void
    {
        $server = $this->newServer('weather');
        $this->reader->next = [$this->discovered('get_current', 'Current weather')];
        $this->sync->syncServer($server);

        // Server goes down.
        $this->reader->next = null;
        $this->reader->throw = new \RuntimeException('connection refused');

        $second = $this->sync->syncServer($server);

        self::assertFalse($second->ok);
        self::assertStringContainsString('connection refused', $server->getLastSyncStatus());

        // The tool row survived — a down server never wipes the catalog.
        self::assertNotNull($this->tools->findOneBy(['name' => 'get_current']));
        self::assertCount(1, $this->tools->findForServerIndexedByName($server));
    }

    public function testPinnedToolWinsOverDiscovered(): void
    {
        $server = $this->newServer('weather');
        $this->reader->next = [$this->discovered('get_current', 'v1 description')];
        $this->sync->syncServer($server);

        // Pin the row: hand-curated content.
        $tool = $this->tools->findOneBy(['name' => 'get_current']);
        $tool->pin('HAND-CURATED', ['type' => 'object']);
        static::getContainer()->get('doctrine')->getManager()->flush();

        // The server now offers a different description — it must not win.
        $this->reader->next = [$this->discovered('get_current', 'v2 description')];
        $second = $this->sync->syncServer($server);

        self::assertSame(0, $second->updated, 'pinned tools are never overwritten');
        self::assertSame(1, $second->drifted, 'drift against pinned content is reported');

        $tool = $this->tools->findOneBy(['name' => 'get_current']);
        self::assertSame('HAND-CURATED', $tool->getDescription());
        self::assertTrue($tool->isPinned());
    }

    public function testRemovedToolIsKeptAndLogged(): void
    {
        $server = $this->newServer('weather');
        $this->reader->next = [
            $this->discovered('get_current', 'Current weather'),
            $this->discovered('get_forecast', 'Forecast'),
        ];
        $this->sync->syncServer($server);

        // Server no longer offers get_forecast.
        $this->reader->next = [$this->discovered('get_current', 'Current weather')];
        $second = $this->sync->syncServer($server);

        self::assertTrue($second->ok);
        self::assertCount(2, $this->tools->findForServerIndexedByName($server), 'both tools kept');
    }

    #[DoesNotPerformAssertions]
    public function testSyncAllWithNoServersIsANoOp(): void
    {
        $this->sync->syncAll();
    }
}
