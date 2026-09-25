<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StepRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One step of a task's step graph (SPEC §13.2).
 *
 * A step is a brief + a toolbox + dependency edges — nothing else. The
 * entity is deliberately dumb: no budgets, no retries, no thresholds
 * (per-run budgets are already effectively per-step under run-per-step,
 * SPEC §13.1). All behavior lives in the engine.
 *
 * `depends_on` is the canonical storage: a list of step ids this step
 * runs after (the edges of the DAG). The nested-array form
 * ([[a, b], [c]]) is a wire/display format only, converted at the
 * authoring boundary — never persisted.
 *
 * Steps are task content: like the brief and toolbox, they are frozen
 * once the task is enabled (SPEC §4.4). Editing steps of an enabled
 * task requires a replacement draft.
 */
#[ORM\Entity(repositoryClass: StepRepository::class)]
#[ORM\Index(name: 'idx_step_task', columns: ['task_id'])]
class Step
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Task $task;

    /** Display order within the task — presentation only, never execution order. */
    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 200)]
    private string $title;

    /**
     * The step brief: what the model running this step is asked to do
     * (SPEC §13.1). Part of the child run's prompt head.
     */
    #[ORM\Column(type: 'text')]
    private string $brief;

    /** How the toolbox is declared: tags or explicit tool names (SPEC §4.1). */
    #[ORM\Column(length: 16, enumType: ToolboxMode::class)]
    private ToolboxMode $toolboxMode;

    /**
     * Toolbox declaration, meaning depends on toolbox_mode — same shape
     * as Task::$toolbox, resolved the same way at child-run start.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $toolbox = [];

    /**
     * The step ids this step depends on (SPEC §13.2): its outputs are
     * available to this step's run via the Inputs block. Canonical DAG
     * edges; validated (no cycles, no self-deps, no dangling references)
     * at task create/update and again at enable/approve.
     *
     * @var list<int>
     */
    #[ORM\Column(type: 'json')]
    private array $dependsOn = [];

    /**
     * @param list<string> $toolbox
     * @param list<int>    $dependsOn
     */
    public function __construct(
        Task $task,
        int $position,
        string $title,
        string $brief,
        ToolboxMode $toolboxMode,
        array $toolbox,
        array $dependsOn = [],
    ) {
        if ($task->isEnabled()) {
            throw new \LogicException('Cannot add a step to an enabled task: create a replacement draft instead (SPEC §4.4, §13.1).');
        }

        $this->task = $task;
        $this->position = $position;
        $this->title = $title;
        $this->brief = $brief;
        $this->toolboxMode = $toolboxMode;
        $this->toolbox = $toolbox;
        $this->dependsOn = $dependsOn;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->assertMutable();
        $this->position = $position;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->assertMutable();
        $this->title = $title;
    }

    public function getBrief(): string
    {
        return $this->brief;
    }

    public function setBrief(string $brief): void
    {
        $this->assertMutable();
        $this->brief = $brief;
    }

    public function getToolboxMode(): ToolboxMode
    {
        return $this->toolboxMode;
    }

    public function setToolboxMode(ToolboxMode $toolboxMode): void
    {
        $this->assertMutable();
        $this->toolboxMode = $toolboxMode;
    }

    /** @return list<string> */
    public function getToolbox(): array
    {
        return $this->toolbox;
    }

    /** @param list<string> $toolbox */
    public function setToolbox(array $toolbox): void
    {
        $this->assertMutable();
        $this->toolbox = $toolbox;
    }

    /** @return list<int> */
    public function getDependsOn(): array
    {
        return $this->dependsOn;
    }

    /** @param list<int> $dependsOn */
    public function setDependsOn(array $dependsOn): void
    {
        $this->assertMutable();
        $this->dependsOn = $dependsOn;
    }

    /**
     * Content mutation guard (SPEC §4.4 via §13.1): steps are task
     * content; an enabled task's step graph is immutable like the rest
     * of it.
     */
    private function assertMutable(): void
    {
        if ($this->task->isEnabled()) {
            throw new \LogicException('Steps of an enabled task are immutable: create a replacement draft instead (SPEC §4.4, §13.1).');
        }
    }
}
