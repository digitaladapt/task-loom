<?php

declare(strict_types=1);

namespace App\Tests\Functional\StepModel;

use App\Admin\TaskAdminService;
use App\Admin\TaskLifecycleException;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The step-graph enforcement gate over the real database (SPEC §13.2):
 * enable and approve refuse an invalid graph — an invalid graph never
 * becomes an enabled task. Also covers the replacement-draft step copy
 * (SPEC §13.1: a replacement carries the task's content, edges remapped).
 */
final class StepGateTest extends KernelTestCase
{
    private TaskAdminService $admin; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskCrud $crud; // @phpstan-ignore property.uninitialized (assigned in setUp)
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
        $em->flush();
        $em->clear();

        $this->admin = static::getContainer()->get(TaskAdminService::class);
        $this->crud = static::getContainer()->get(TaskCrud::class);
        $this->steps = static::getContainer()->get(StepRepository::class);
        $this->tasks = static::getContainer()->get(TaskRepository::class);
    }

    public function testEnableSucceedsWithValidGraph(): void
    {
        $task = $this->draftTask();
        $weather = $this->step($task, 1, 'Weather');
        $summary = $this->step($task, 2, 'Summary', [$weather->getId()]);

        $this->admin->enableTask($task->getId());

        $this->em()->refresh($task);
        self::assertTrue($task->isEnabled());
        self::assertNotNull($summary->getId());
    }

    public function testEnableSucceedsWithZeroSteps(): void
    {
        // SPEC §13.1: steps are fully optional; zero-step tasks enable as v1.
        $task = $this->draftTask();

        $this->admin->enableTask($task->getId());

        $this->em()->refresh($task);
        self::assertTrue($task->isEnabled());
    }

    public function testEnableIsRefusedOnCycle(): void
    {
        $task = $this->draftTask();
        $a = $this->step($task, 1, 'A', []);
        $b = $this->step($task, 2, 'B', [$a->getId()]);
        // Close the cycle: A now depends on B.
        $a->setDependsOn([$b->getId()]);
        $this->steps->save($a);

        try {
            $this->admin->enableTask($task->getId());
            self::fail('Expected TaskLifecycleException.');
        } catch (TaskLifecycleException $e) {
            self::assertStringContainsString('cycle', strtolower($e->getMessage()));
        }

        $this->em()->refresh($task);
        self::assertFalse($task->isEnabled(), 'invalid graph must never become an enabled task');
    }

    public function testEnableIsRefusedOnSelfDependency(): void
    {
        $task = $this->draftTask();
        $step = $this->step($task, 1, 'Weather');
        $step->setDependsOn([$step->getId()]);
        $this->steps->save($step);

        try {
            $this->admin->enableTask($task->getId());
            self::fail('Expected TaskLifecycleException.');
        } catch (TaskLifecycleException $e) {
            self::assertStringContainsString('depends on itself', $e->getMessage());
        }

        $this->em()->refresh($task);
        self::assertFalse($task->isEnabled());
    }

    public function testEnableIsRefusedOnCrossTaskDependency(): void
    {
        $other = $this->draftTask('Other task');
        $otherStep = $this->step($other, 1, 'Other step');

        $task = $this->draftTask();
        $this->step($task, 1, 'Weather', [$otherStep->getId()]);

        try {
            $this->admin->enableTask($task->getId());
            self::fail('Expected TaskLifecycleException.');
        } catch (TaskLifecycleException $e) {
            self::assertStringContainsString('not a step of this task', $e->getMessage());
        }

        $this->em()->refresh($task);
        self::assertFalse($task->isEnabled());
    }

    public function testApproveIsRefusedOnInvalidGraphAndOriginalUntouched(): void
    {
        $original = $this->draftTask('Original');
        $this->admin->enableTask($original->getId());

        // Replacement draft with a broken graph: edge to a nonexistent step.
        $draft = $original->createReplacementDraft(TaskAuthor::User);
        $this->tasks->save($draft);
        $step = $this->step($draft, 1, 'Dangling', [999999]);

        try {
            $this->admin->approveTask($draft->getId());
            self::fail('Expected TaskLifecycleException.');
        } catch (TaskLifecycleException $e) {
            self::assertStringContainsString('Cannot approve', $e->getMessage());
        }

        // The atomic swap must not have moved a single row.
        $this->em()->refresh($original);
        $this->em()->refresh($draft);
        self::assertTrue($original->isEnabled(), 'original stays enabled');
        self::assertFalse($original->isSuperseded());
        self::assertFalse($draft->isEnabled());
        self::assertFalse($draft->isArchived());
        self::assertNotNull($step->getId());
    }

    public function testApproveSucceedsWithValidGraph(): void
    {
        $original = $this->draftTask('Original');
        $this->admin->enableTask($original->getId());

        $draft = $original->createReplacementDraft(TaskAuthor::User);
        $this->tasks->save($draft);
        $a = $this->step($draft, 1, 'A');
        $this->step($draft, 2, 'B', [$a->getId()]);

        $this->admin->approveTask($draft->getId());

        $this->em()->refresh($draft);
        $this->em()->refresh($original);
        self::assertTrue($draft->isEnabled());
        self::assertTrue($original->isSuperseded());
    }

    public function testReplacementDraftCopiesStepGraphWithRemappedEdges(): void
    {
        $original = $this->draftTask('Original');
        $weather = $this->step($original, 1, 'Weather');
        $calendar = $this->step($original, 2, 'Calendar');
        $this->step($original, 3, 'Summary', [$weather->getId(), $calendar->getId()]);
        $this->admin->enableTask($original->getId());

        // The agent updates the enabled task → replacement draft.
        $draft = $this->crud->update($original->getId(), ['brief' => 'Tighter brief.']);

        $originalSteps = $this->steps->findForTask($original);
        $draftSteps = $this->steps->findForTask($draft);

        self::assertCount(3, $originalSteps);
        self::assertCount(3, $draftSteps);

        // Same titles/positions/briefs, all-new ids.
        $originalIds = array_map(static fn (Step $s) => $s->getId(), $originalSteps);
        $draftIds = array_map(static fn (Step $s) => $s->getId(), $draftSteps);
        self::assertSame([], array_intersect($originalIds, $draftIds));

        self::assertSame(['Weather', 'Calendar', 'Summary'], array_map(static fn (Step $s) => $s->getTitle(), $draftSteps));

        // Edges remapped: Summary depends on the CLONES of Weather and
        // Calendar — not on the original rows.
        $summary = $draftSteps[2];
        self::assertSame([$draftSteps[0]->getId(), $draftSteps[1]->getId()], $summary->getDependsOn());
        self::assertSame([], array_intersect($summary->getDependsOn(), $originalIds));
    }

    public function testReplacementDraftWithoutStepsCopiesNothing(): void
    {
        $original = $this->draftTask('Original');
        $this->admin->enableTask($original->getId());

        $draft = $this->crud->update($original->getId(), ['title' => 'Renamed']);

        self::assertSame([], $this->steps->findForTask($draft));
        self::assertSame([], $this->steps->findForTask($original));
    }

    public function testStepGraphIsFrozenAfterEnable(): void
    {
        // SPEC §4.4 via §13.1: once enabled, the graph cannot be edited.
        $task = $this->draftTask();
        $step = $this->step($task, 1, 'Weather');
        $this->admin->enableTask($task->getId());

        $this->em()->refresh($step);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $step->setDependsOn([]);
    }

    // --------------------------------------------------------------- helpers

    private function draftTask(string $title = 'Morning Briefing'): Task
    {
        $task = new Task($title, 'Compose the briefing.', TaskKind::Run, ToolboxMode::Tags, ['weather'], TaskAuthor::User);
        $this->tasks->save($task);

        return $task;
    }

    /**
     * @param list<int> $dependsOn
     */
    private function step(Task $task, int $position, string $title, array $dependsOn = []): Step
    {
        $step = new Step($task, $position, $title, "Do {$title}.", ToolboxMode::Explicit, ['get_weather'], $dependsOn);
        $this->steps->save($step);

        return $step;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
