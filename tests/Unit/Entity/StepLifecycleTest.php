<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use PHPUnit\Framework\TestCase;

/**
 * Step entity semantics (SPEC §13.1, §4.4): steps are task content —
 * editable on drafts, immutable once the task is enabled. depends_on is
 * the canonical edge storage.
 */
final class StepLifecycleTest extends TestCase
{
    private function makeTask(): Task
    {
        return new Task(
            'Morning Briefing',
            'Compose the briefing.',
            TaskKind::Run,
            ToolboxMode::Tags,
            ['weather', 'calendar'],
            TaskAuthor::User,
        );
    }

    /**
     * @param list<int> $dependsOn
     */
    private function makeStep(Task $task, string $title = 'Weather', array $dependsOn = []): Step
    {
        return new Step(
            $task,
            1,
            $title,
            'Fetch the weather.',
            ToolboxMode::Explicit,
            ['get_weather'],
            $dependsOn,
        );
    }

    public function testStepCarriesBriefToolboxAndEdges(): void
    {
        $step = $this->makeStep($this->makeTask(), dependsOn: [7, 8]);

        self::assertSame('Weather', $step->getTitle());
        self::assertSame('Fetch the weather.', $step->getBrief());
        self::assertSame(ToolboxMode::Explicit, $step->getToolboxMode());
        self::assertSame(['get_weather'], $step->getToolbox());
        self::assertSame([7, 8], $step->getDependsOn());
    }

    public function testStepOnDraftIsEditable(): void
    {
        $step = $this->makeStep($this->makeTask());

        $step->setTitle('Weather (v2)');
        $step->setBrief('Fetch the weather and the forecast.');
        $step->setToolbox(['get_weather', 'get_forecast']);
        $step->setToolboxMode(ToolboxMode::Tags);
        $step->setDependsOn([1, 2]);
        $step->setPosition(3);

        self::assertSame('Weather (v2)', $step->getTitle());
        self::assertSame('Fetch the weather and the forecast.', $step->getBrief());
        self::assertSame(['get_weather', 'get_forecast'], $step->getToolbox());
        self::assertSame(ToolboxMode::Tags, $step->getToolboxMode());
        self::assertSame([1, 2], $step->getDependsOn());
        self::assertSame(3, $step->getPosition());
    }

    public function testStepOfEnabledTaskIsImmutable(): void
    {
        $task = $this->makeTask();
        $step = $this->makeStep($task);
        $task->enable();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $step->setBrief('Sneaky mutation');
    }

    public function testDependsOnMutationOnEnabledTaskIsRefused(): void
    {
        $task = $this->makeTask();
        $step = $this->makeStep($task);
        $task->enable();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $step->setDependsOn([1]);
    }

    public function testAddingStepToEnabledTaskIsRefused(): void
    {
        $task = $this->makeTask();
        $task->enable();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('replacement draft');

        $this->makeStep($task);
    }

    public function testZeroStepTaskIsUnaffectedByStepModel(): void
    {
        // SPEC §13.1: a task with zero steps behaves exactly as v1 — the
        // Step entity adds no obligation to the task itself.
        $task = $this->makeTask();
        $task->enable();

        self::assertTrue($task->isEnabled());
        self::assertFalse($task->isDraft());
    }
}
