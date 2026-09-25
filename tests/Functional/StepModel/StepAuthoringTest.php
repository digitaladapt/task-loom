<?php

declare(strict_types=1);

namespace App\Tests\Functional\StepModel;

use App\Entity\Step;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use App\StepModel\StepFormatException;
use App\StepModel\StepGraphCodec;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Step authoring through the gated CRUD layer over the real database
 * (SPEC §13.2: arrays in, edges stored; §4.3: everything persists
 * disabled; §4.4: enabled tasks are immutable).
 */
final class StepAuthoringTest extends KernelTestCase
{
    private TaskCrud $crud; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private StepRepository $steps; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskRepository $tasks; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private StepGraphCodec $codec; // @phpstan-ignore property.uninitialized (assigned in setUp)

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
        $em->flush();
        $em->clear();

        $this->crud = static::getContainer()->get(TaskCrud::class);
        $this->steps = static::getContainer()->get(StepRepository::class);
        $this->tasks = static::getContainer()->get(TaskRepository::class);
        $this->codec = static::getContainer()->get(StepGraphCodec::class);
    }

    public function testCreateWithStepsStoresEdgesAndPersistsDisabled(): void
    {
        $task = $this->crud->create('Briefing', 'Compose.', TaskKind::Run, ToolboxMode::Tags, ['weather'], null, [
            [
                ['title' => 'Weather', 'brief' => 'Fetch weather.'],
                ['title' => 'Calendar', 'brief' => 'Fetch calendar.'],
            ],
            [
                ['title' => 'Summary', 'brief' => 'Summarize.'],
            ],
        ]);

        self::assertFalse($task->isEnabled(), 'the gate holds for stepped tasks too (SPEC §4.3)');

        $steps = $this->steps->findForTask($task);
        self::assertCount(3, $steps);

        // Positions assigned in wire order.
        self::assertSame([1, 2, 3], array_map(static fn (Step $s) => $s->getPosition(), $steps));

        // Edges: the summary depends on BOTH first-level steps.
        self::assertSame([], $steps[0]->getDependsOn());
        self::assertSame([], $steps[1]->getDependsOn());
        self::assertSame([$steps[0]->getId(), $steps[1]->getId()], $steps[2]->getDependsOn());
    }

    public function testCreateWithoutStepsIsAZeroStepTask(): void
    {
        // SPEC §13.1: steps are fully optional.
        $task = $this->crud->create('Plain', 'Do the thing.', TaskKind::Run, ToolboxMode::Tags, ['weather'], null);

        self::assertSame([], $this->steps->findForTask($task));
    }

    public function testCreateRejectsMalformedStepsBeforeAnyWrite(): void
    {
        try {
            $this->crud->create('Broken', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
                [['title' => '', 'brief' => 'x']],
            ]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertStringContainsString('steps[0][0].title', $e->getMessage());
        }

        self::assertSame([], $this->tasks->findBy([]), 'no partial task persisted');
    }

    public function testUpdateDraftReplacesEntireGraph(): void
    {
        $task = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
            [['title' => 'Old', 'brief' => 'old.']],
        ]);
        $oldIds = array_map(static fn (Step $s) => $s->getId(), $this->steps->findForTask($task));

        $this->crud->update($task->getId(), ['steps' => [
            [['title' => 'New A', 'brief' => 'a.']],
            [['title' => 'New B', 'brief' => 'b.']],
        ]]);

        $steps = $this->steps->findForTask($task);
        self::assertSame(['New A', 'New B'], array_map(static fn (Step $s) => $s->getTitle(), $steps));

        // The old rows are gone — replaced, not appended.
        $remaining = array_map(static fn (Step $s) => $s->getId(), $steps);
        self::assertSame([], array_intersect($oldIds, $remaining));

        // Edges for the rebuilt graph.
        self::assertSame([$steps[0]->getId()], $steps[1]->getDependsOn());
    }

    public function testUpdateWithEmptyStepsClearsGraph(): void
    {
        $task = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
            [['title' => 'A', 'brief' => 'a.']],
        ]);

        $this->crud->update($task->getId(), ['steps' => []]);

        self::assertSame([], $this->steps->findForTask($task));
    }

    public function testUpdateWithoutStepsKeyLeavesGraphUntouched(): void
    {
        $task = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
            [['title' => 'Keep me', 'brief' => 'k.']],
        ]);

        $this->crud->update($task->getId(), ['brief' => 'Edited brief.']);

        $steps = $this->steps->findForTask($task);
        self::assertCount(1, $steps);
        self::assertSame('Keep me', $steps[0]->getTitle());
    }

    public function testUpdateEnabledTaskCreatesReplacementDraftWithNewSteps(): void
    {
        $original = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
            [['title' => 'Original step', 'brief' => 'o.']],
        ]);
        $original->enable();
        $this->tasks->save($original);

        $draft = $this->crud->update($original->getId(), ['steps' => [
            [['title' => 'Replacement step', 'brief' => 'r.']],
        ]]);

        // The original keeps its graph, untouched (SPEC §4.4).
        $originalSteps = $this->steps->findForTask($original);
        self::assertSame(['Original step'], array_map(static fn (Step $s) => $s->getTitle(), $originalSteps));

        // The draft carries the replacement graph.
        $draftSteps = $this->steps->findForTask($draft);
        self::assertSame(['Replacement step'], array_map(static fn (Step $s) => $s->getTitle(), $draftSteps));
        self::assertFalse($draft->isEnabled());
    }

    public function testUpdateEnabledTaskWithoutStepsClonesGraphWithRemappedEdges(): void
    {
        $original = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
            [['title' => 'A', 'brief' => 'a.'], ['title' => 'B', 'brief' => 'b.']],
            [['title' => 'C', 'brief' => 'c.']],
        ]);
        $original->enable();
        $this->tasks->save($original);

        $draft = $this->crud->update($original->getId(), ['brief' => 'Tighter.']);

        $originalSteps = $this->steps->findForTask($original);
        $draftSteps = $this->steps->findForTask($draft);

        self::assertCount(3, $draftSteps);
        self::assertSame([], array_intersect(
            array_map(static fn (Step $s) => $s->getId(), $originalSteps),
            array_map(static fn (Step $s) => $s->getId(), $draftSteps),
        ));

        // Edges remapped onto the clones.
        self::assertSame([$draftSteps[0]->getId(), $draftSteps[1]->getId()], $draftSteps[2]->getDependsOn());
    }

    public function testUpdateRejectsMalformedStepsAndLeavesTaskUntouched(): void
    {
        $task = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null, [
            [['title' => 'Survivor', 'brief' => 's.']],
        ]);

        try {
            $this->crud->update($task->getId(), ['brief' => 'Edited.', 'steps' => [[['title' => '', 'brief' => 'x']]]]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException) {
            // expected
        }

        $this->em()->refresh($task);
        self::assertSame('B.', $task->getBrief(), 'the whole update rolled back, not just the steps');
        self::assertSame(['Survivor'], array_map(static fn (Step $s) => $s->getTitle(), $this->steps->findForTask($task)));
    }

    public function testUpdateAcceptsWireEnumStrings(): void
    {
        // Regression: nested `changes` values arrive as JSON strings over
        // MCP; the persistence layer must coerce them (TypeError before).
        $task = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null);

        $updated = $this->crud->update($task->getId(), [
            'kind' => 'session',
            'toolbox_mode' => 'explicit',
        ]);

        self::assertSame(TaskKind::Session, $updated->getKind());
        self::assertSame(ToolboxMode::Explicit, $updated->getToolboxMode());
    }

    public function testUpdateRejectsUnknownEnumStrings(): void
    {
        $task = $this->crud->create('T', 'B.', TaskKind::Run, ToolboxMode::Tags, [], null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid kind "nonsense"');

        $this->crud->update($task->getId(), ['kind' => 'nonsense']);
    }

    public function testRenderRoundTripOverTheDatabase(): void
    {
        $input = [
            [
                ['title' => 'Weather', 'brief' => 'Fetch weather.', 'toolbox_mode' => 'explicit', 'toolbox' => ['get_weather']],
                ['title' => 'Calendar', 'brief' => 'Fetch calendar.', 'toolbox_mode' => 'tags', 'toolbox' => ['calendar']],
            ],
            [
                ['title' => 'Summary', 'brief' => 'Summarize all.'],
            ],
        ];

        $task = $this->crud->create('Briefing', 'Compose.', TaskKind::Run, ToolboxMode::Tags, ['weather'], null, $input);

        // Normalize the input the way the codec's defaults do: missing
        // toolbox_mode is "explicit", missing toolbox is [].
        self::assertSame(
            array_map(fn (array $level) => array_map(fn (array $s) => [
                'title' => $s['title'],
                'brief' => $s['brief'],
                'toolbox_mode' => $s['toolbox_mode'] ?? 'explicit',
                'toolbox' => $s['toolbox'] ?? [],
            ], $level), $input),
            $this->codec->render($this->steps->findForTask($task)),
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
