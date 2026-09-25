<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\StepOverviewPresenter;
use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The admin UI's step surface (SPEC §13.6 + §13.2): steps render grouped
 * into levels, each with its own toolbox preview, and an invalid graph
 * shows its problems without throwing — the enable/approve gate is the
 * enforcement point, the detail page is where the human sees it first.
 */
final class StepOverviewTest extends KernelTestCase
{
    private StepOverviewPresenter $presenter; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private StepRepository $steps; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskRepository $tasks; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $em->flush();
        $em->clear();

        $this->presenter = static::getContainer()->get(StepOverviewPresenter::class);
        $this->steps = static::getContainer()->get(StepRepository::class);
        $this->tasks = static::getContainer()->get(TaskRepository::class);
    }

    public function testZeroStepTaskShowsNothing(): void
    {
        $task = $this->draftTask();

        $overview = $this->presenter->present($task);

        self::assertTrue($overview->isEmpty());
        self::assertTrue($overview->isOk());
        self::assertSame(0, $overview->levelCount());
    }

    public function testStepsPresentWithLevelsAndPreviews(): void
    {
        $task = $this->draftTask();
        $weather = $this->step($task, 1, 'Weather', ToolboxMode::Explicit, ['get_weather']);
        $calendar = $this->step($task, 2, 'Calendar', ToolboxMode::Tags, ['calendar']);
        $summary = $this->step($task, 3, 'Summary', ToolboxMode::Explicit, [], [$weather->getId(), $calendar->getId()]);

        // Catalog: weather tool exists, calendar tag has no carrier.
        $this->catalogTool('get_weather', ['weather']);

        $overview = $this->presenter->present($task);

        self::assertFalse($overview->isEmpty());
        self::assertSame(3, \count($overview->views));
        self::assertSame([0, 0, 1], array_map(static fn ($v) => $v->level, $overview->views));

        // The weather step resolves.
        self::assertCount(1, $overview->views[0]->preview->resolved);
        self::assertTrue($overview->views[0]->preview->isOk());

        // The calendar step's tag has no carrier: problem surfaces.
        self::assertTrue($overview->views[1]->preview->isEmpty());
        self::assertFalse($overview->views[1]->preview->isOk());
        self::assertStringContainsString('No tool carries the tag "calendar"', $overview->views[1]->preview->problems[0]);

        // The graph itself is valid (validation gate would pass).
        self::assertTrue($overview->isOk());
    }

    public function testEmptyToolboxStepGetsTheDispatchWarning(): void
    {
        $task = $this->draftTask();
        $this->step($task, 1, 'Bare', ToolboxMode::Explicit, []);

        $overview = $this->presenter->present($task);

        self::assertTrue($overview->views[0]->preview->isEmpty());
    }

    public function testInvalidGraphShowsProblemsWithoutThrowing(): void
    {
        $task = $this->draftTask();
        $a = $this->step($task, 1, 'A');
        $b = $this->step($task, 2, 'B', ToolboxMode::Explicit, [], [$a->getId()]);
        // Close the cycle: A depends on B.
        $a->setDependsOn([$b->getId()]);
        $this->steps->save($a);

        $overview = $this->presenter->present($task);

        self::assertFalse($overview->isOk());
        self::assertStringContainsString('Dependency cycle', $overview->problems[0]);

        // Display still completes: both steps present, leveled sanely.
        self::assertSame(2, \count($overview->views));
    }

    public function testLevelCountCountsDistinctLevels(): void
    {
        $task = $this->draftTask();
        $a = $this->step($task, 1, 'A');
        $b = $this->step($task, 2, 'B');
        $this->step($task, 3, 'C', ToolboxMode::Explicit, [], [$a->getId(), $b->getId()]);

        self::assertSame(2, $this->presenter->present($task)->levelCount());
    }

    // --------------------------------------------------------------- helpers

    private function draftTask(): Task
    {
        $task = new Task('Stepped', 'Compose.', TaskKind::Run, ToolboxMode::Tags, ['weather'], TaskAuthor::User);
        $this->tasks->save($task);

        return $task;
    }

    /**
     * @param list<string> $toolbox
     * @param list<int>    $dependsOn
     */
    private function step(Task $task, int $position, string $title, ToolboxMode $mode = ToolboxMode::Explicit, array $toolbox = [], array $dependsOn = []): Step
    {
        $step = new Step($task, $position, $title, "Do {$title}.", $mode, $toolbox, $dependsOn);
        $this->steps->save($step);

        return $step;
    }

    /**
     * @param list<string> $tags
     */
    private function catalogTool(string $name, array $tags): void
    {
        $em = $this->em();
        $server = new McpServer('catalog-'.uniqid(), 'https://server.example/mcp', ServerProtocol::Mcp);
        $em->persist($server);

        $tool = new Tool($server, $name, 'test tool', [], array_merge([$server->getName()], $tags));
        $em->persist($tool);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
