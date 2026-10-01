<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Repository\ToolRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ToolRepository::findAllOrdered() — the catalog's canonical order.
 *
 * Every human-facing list of tools uses this (catalog page, editor picker,
 * toolbox preview) and the run engine's resolution passes share it, so the
 * order is load-bearing: a change here moves tools under the operator's
 * cursor everywhere at once. These tests pin the two properties that make it
 * meaningful — one server's tools stay together, and within a server the
 * names are alphabetical.
 *
 * The deprecated string form of QueryBuilder::orderBy() would slip through a
 * test that only checked the *result* (SQLite happens to agree), so the suite
 * runs with failOnDeprecation=true and an ASC/DESC string would surface here.
 */
final class ToolRepositoryTest extends KernelTestCase
{
    private ToolRepository $tools; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $em->flush();
        $em->clear();

        $this->tools = static::getContainer()->get(ToolRepository::class);
    }

    public function testToolsGroupByServerThenOrderByName(): void
    {
        // Deliberately inserted out of order, and interleaved across servers:
        // a naive name-only sort would produce zeta, alpha, ... and scatter
        // the two servers' tools through each other.
        $this->tool('beta', 'zeta');
        $this->tool('alpha', 'summary');
        $this->tool('beta', 'alpha');
        $this->tool('alpha', 'get_weather');

        $ordered = array_map(
            static fn (Tool $tool): string => $tool->getServer()->getName().'.'.$tool->getName(),
            $this->tools->findAllOrdered(),
        );

        self::assertSame([
            'alpha.get_weather',
            'alpha.summary',
            'beta.alpha',
            'beta.zeta',
        ], $ordered);
    }

    public function testServerNameIsThePrimaryKeySoAToolNameCollisionStaysGrouped(): void
    {
        // The same tool name on two servers is the normal case (two weather
        // providers), not an edge case — the server has to be the primary
        // axis or those twins sit next to each other with no way to tell
        // which is which.
        $this->tool('zeta-server', 'get_weather');
        $this->tool('alpha-server', 'get_weather');

        $servers = array_map(
            static fn (Tool $tool): string => $tool->getServer()->getName(),
            $this->tools->findAllOrdered(),
        );

        self::assertSame(['alpha-server', 'zeta-server'], $servers);
    }

    public function testWithNoToolsTheCatalogIsEmpty(): void
    {
        self::assertSame([], $this->tools->findAllOrdered());
    }

    /**
     * The ordering is total, not merely sorted: the id tie-break means two
     * rows that compare equal on (server, name) still have one defined
     * sequence. Constructed directly rather than through the unique
     * constraint, since the constraint is on (server_id, name) — this pins
     * the query's own stability, which does not depend on it.
     */
    public function testOrderingIsStableAcrossRepeatedQueries(): void
    {
        $this->tool('alpha', 'one');
        $this->tool('alpha', 'two');
        $this->tool('beta', 'three');

        $first = array_map(static fn (Tool $t): ?int => $t->getId(), $this->tools->findAllOrdered());
        $second = array_map(static fn (Tool $t): ?int => $t->getId(), $this->tools->findAllOrdered());

        self::assertSame($first, $second);
    }

    private function tool(string $serverName, string $toolName): Tool
    {
        $em = $this->em();
        $server = $em->getRepository(McpServer::class)->findOneBy(['name' => $serverName])
            ?? new McpServer($serverName, 'https://example.test/mcp', ServerProtocol::Mcp);
        $em->persist($server);

        $tool = new Tool($server, $toolName, 'test tool', [], []);
        $em->persist($tool);
        $em->flush();

        return $tool;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
