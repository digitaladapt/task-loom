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
use App\RunEngine\ToolboxPreviewer;
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
    private ToolboxPreviewer $previewer; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StreamEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->resolver = static::getContainer()->get(ToolboxResolver::class);
        $this->previewer = static::getContainer()->get(ToolboxPreviewer::class);
    }

    public function testASessionsToolboxIsItsCatalogToolsPlusTheHarnessTools(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->task(TaskKind::Session, ToolboxMode::Explicit, ['get_weather']);

        $names = array_map(static fn ($tool): string => $tool->getName(), $this->resolver->resolve($task));

        self::assertSame(['get_weather', 'session_note', 'session_objective', 'session_declare'], $names, 'catalog tools first, the harness\'s own after — one frozen list');
    }

    public function testASessionMayResolveZeroMcpToolsAndStillResolve(): void
    {
        // The workspace is optional (the design note demotes it to an
        // ordinary tool): a session dedicated to thinking can run with no
        // MCP server at all. The harness tools are always there.
        $task = $this->task(TaskKind::Session, ToolboxMode::Tags, ['nothing-carries-this-tag']);

        $names = array_map(static fn ($tool): string => $tool->getName(), $this->resolver->resolve($task));

        self::assertSame(['session_note', 'session_objective', 'session_declare'], $names);
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

    // ── The preview mirror (SPEC §4.1; the task page's diagnostic) ───────

    /**
     * What the task page shows must include the harness half: "what would
     * resolve() say, without throwing" now has two components, and a preview
     * that showed only the declared half would read as though the session
     * tools were absent — the exact misreading this pins against.
     */
    public function testThePreviewShowsBothHalvesForASession(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->task(TaskKind::Session, ToolboxMode::Explicit, ['get_weather']);

        $preview = $this->previewer->preview($task);

        self::assertSame(['get_weather'], array_map(static fn ($tool): string => $tool->getName(), $preview->resolved));
        self::assertSame(['session_note', 'session_objective', 'session_declare'], $preview->harness, 'the harness half is shown, not only implied');
        self::assertFalse($preview->isEmpty());
        self::assertTrue($preview->isOk());
    }

    /**
     * A session whose declared half is empty is not a dispatch failure — the
     * harness tools remain — so the preview must not say it is one. An
     * ordinary task's empty toolbox still is, and still says so.
     */
    public function testASessionWithAnEmptyDeclaredHalfIsNotPreviewedAsAFailure(): void
    {
        $task = $this->task(TaskKind::Session, ToolboxMode::Tags, []);

        $preview = $this->previewer->preview($task);

        self::assertFalse($preview->isEmpty(), 'the harness tools remain — the toolbox is not empty');
        self::assertSame(['session_note', 'session_objective', 'session_declare'], $preview->harness);

        $problems = implode('; ', $preview->problems);
        self::assertStringNotContainsString('would be empty', $problems);
        self::assertStringContainsString('still included', $problems, 'the no-tags note says what is still there');
    }

    /**
     * The reserved-name collision is previewed as a problem rather than
     * thrown (display stays total), and the message names the reservation.
     */
    public function testThePreviewFlagsAReservedNameInsteadOfThrowing(): void
    {
        $this->catalogTool('session_note');
        $task = $this->task(TaskKind::Session, ToolboxMode::Explicit, ['session_note']);

        $preview = $this->previewer->preview($task);

        self::assertStringContainsString('reserved for the harness', implode('; ', $preview->problems));
        self::assertFalse($preview->isOk());
    }

    /**
     * And an ordinary task's preview is exactly what it was: no harness half,
     * and an empty declaration is still the loud failure.
     */
    public function testAnOrdinaryTasksPreviewIsUnchanged(): void
    {
        $task = $this->task(TaskKind::Run, ToolboxMode::Tags, []);

        $preview = $this->previewer->preview($task);

        self::assertSame([], $preview->harness, 'nothing is added for a run-kind task');
        self::assertTrue($preview->isEmpty());
        self::assertStringContainsString('would be empty', implode('; ', $preview->problems));
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
