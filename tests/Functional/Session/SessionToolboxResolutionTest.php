<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\RunEngine\ToolboxResolutionException;
use App\RunEngine\ToolboxResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The harness-tool clause at resolution time (SPEC §4.1, docs/design/
 * SESSION_TASKS.md §5, build order step 3): a session's frozen toolbox is
 * its operator-selected MCP tools **plus** the harness's own session tools;
 * an ordinary task is exactly what it was; and a catalog tool carrying a
 * harness name is refused at resolution rather than shadowed at dispatch.
 *
 * These assertions are at the resolver — the single place a toolbox is
 * resolved — so "the whole toolbox is frozen at slice start exactly as
 * before" is provable without any session being dispatchable yet.
 */
final class SessionToolboxResolutionTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolboxResolver $resolver; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->resolver = static::getContainer()->get(ToolboxResolver::class);
    }

    public function testASessionsToolboxIsItsCatalogToolsPlusTheHarnessTools(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->task(TaskKind::Session, ToolboxMode::Explicit, ['get_weather']);

        $names = array_map(static fn ($tool): string => $tool->getName(), $this->resolver->resolve($task));

        self::assertSame(['get_weather', 'session_note', 'session_objective'], $names, 'catalog tools first, the harness\'s own after — one frozen list');
    }

    public function testASessionMayResolveZeroMcpToolsAndStillResolve(): void
    {
        // The workspace is optional (the design note demotes it to an
        // ordinary tool): a session dedicated to thinking can run with no
        // MCP server at all. The harness tools are always there.
        $task = $this->task(TaskKind::Session, ToolboxMode::Tags, ['nothing-carries-this-tag']);

        $names = array_map(static fn ($tool): string => $tool->getName(), $this->resolver->resolve($task));

        self::assertSame(['session_note', 'session_objective'], $names);
    }

    public function testAnOrdinaryTaskResolvesExactlyAsBefore(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->task(TaskKind::Run, ToolboxMode::Explicit, ['get_weather']);

        $names = array_map(static fn ($tool): string => $tool->getName(), $this->resolver->resolve($task));

        self::assertSame(['get_weather'], $names, 'no harness tools for a run kind task — sessions only');
    }

    public function testAnOrdinaryTaskStillFailsLoudlyOnAnEmptyToolbox(): void
    {
        $task = $this->task(TaskKind::Run, ToolboxMode::Tags, ['nothing-carries-this-tag']);

        $this->expectException(ToolboxResolutionException::class);
        $this->expectExceptionMessage('empty toolbox');

        $this->resolver->resolve($task);
    }

    public function testACatalogToolNamedLikeAHarnessToolIsRefusedForASession(): void
    {
        $this->catalogTool('session_note');
        $task = $this->task(TaskKind::Session, ToolboxMode::Explicit, ['session_note']);

        // Refused at resolution, not shadowed at dispatch: the model's
        // "session_note" must mean exactly one thing for the session's whole
        // life, and which one it is cannot depend on catalog state.
        $this->expectException(ToolboxResolutionException::class);
        $this->expectExceptionMessage('reserved for the harness');

        $this->resolver->resolve($task);
    }

    public function testTheReservedNameIsRefusedByTheTagRouteToo(): void
    {
        $this->catalogTool('session_objective', ['core']);
        $task = $this->task(TaskKind::Session, ToolboxMode::Tags, ['core']);

        $this->expectException(ToolboxResolutionException::class);
        $this->expectExceptionMessage('reserved for the harness');

        $this->resolver->resolve($task);
    }

    public function testTheReservedNameIsOnlyReservedForSessions(): void
    {
        // A run-kind task never receives harness tools, so there is nothing
        // for a catalog name to shadow — the name is the catalog's there.
        $this->catalogTool('session_note');
        $task = $this->task(TaskKind::Run, ToolboxMode::Explicit, ['session_note']);

        $names = array_map(static fn ($tool): string => $tool->getName(), $this->resolver->resolve($task));

        self::assertSame(['session_note'], $names);
    }

    /**
     * @param list<string> $tags
     */
    private function catalogTool(string $name, array $tags = []): Tool
    {
        $server = new McpServer('test-server', 'https://server.example/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);

        $tool = new Tool($server, $name, 'test tool', ['type' => 'object'], $tags);
        $this->em->persist($tool);
        $this->em->flush();

        return $tool;
    }

    /**
     * @param list<string> $toolbox
     */
    private function task(TaskKind $kind, ToolboxMode $mode, array $toolbox): Task
    {
        $task = new Task('Resolution probe', 'Probe.', $kind, $mode, $toolbox, TaskAuthor::User);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }
}
