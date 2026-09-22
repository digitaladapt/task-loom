<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A task: a brief, a toolbox, a schedule — and once enabled, an immutable
 * record (SPEC §4.4).
 *
 * Drafts (enabled = false) are freely editable. Enabled tasks are never
 * mutated — not by agents, not by users: any content update creates a
 * replacement draft via replacementFor. The only mutations an enabled task
 * receives are lifecycle flags (disabling/archiving/superseding), never its
 * content (title, brief, toolbox, schedule, kind).
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Index(name: 'idx_task_replacement_for', columns: ['replacement_for_id'])]
#[ORM\HasLifecycleCallbacks]
class Task
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\Column(length: 200)]
    private string $title;

    /**
     * The task brief: what the model is asked to do. Free text, part of the
     * task prompt (SPEC §4.1).
     */
    #[ORM\Column(type: 'text')]
    private string $brief;

    /** run | session (SPEC §9). */
    #[ORM\Column(length: 16, enumType: TaskKind::class)]
    private TaskKind $kind;

    /** How the toolbox is declared: tags or explicit tool names (SPEC §4.1). */
    #[ORM\Column(length: 16, enumType: ToolboxMode::class)]
    private ToolboxMode $toolboxMode;

    /**
     * Toolbox declaration, meaning depends on toolbox_mode:
     *  - tags:     list of tags to resolve against the catalog
     *  - explicit: list of tool names, resolved exactly
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $toolbox = [];

    /**
     * Cron expression (v1.1 scheduler); nullable because v1's only trigger
     * is manual Run-now.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $schedule = null;

    /**
     * A task is runnable only when enabled. Agent-authored tasks and
     * replacements always land with enabled = false (SPEC §4.3): the human
     * is the enable switch.
     */
    #[ORM\Column]
    private bool $enabled = false;

    /** Who authored this version of the task (SPEC §4.3). */
    #[ORM\Column(length: 16, enumType: TaskAuthor::class)]
    private TaskAuthor $createdBy;

    /**
     * If this row is a replacement draft: the enabled task it would replace.
     * Nullable for original tasks.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replacement_for_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?self $replacementFor = null;

    /**
     * If this row was replaced by an approved successor: that successor.
     * Set atomically when the replacement is approved.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'superseded_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?self $supersededBy = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @param list<string> $toolbox
     */
    public function __construct(
        string $title,
        string $brief,
        TaskKind $kind,
        ToolboxMode $toolboxMode,
        array $toolbox,
        TaskAuthor $createdBy,
    ) {
        $this->title = $title;
        $this->brief = $brief;
        $this->kind = $kind;
        $this->toolboxMode = $toolboxMode;
        $this->toolbox = $toolbox;
        $this->createdBy = $createdBy;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getKind(): TaskKind
    {
        return $this->kind;
    }

    public function setKind(TaskKind $kind): void
    {
        $this->assertMutable();
        $this->kind = $kind;
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

    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    public function setSchedule(?string $schedule): void
    {
        $this->assertMutable();
        $this->schedule = $schedule;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Lifecycle mutation only (SPEC §4.4): enabling a task is a manual,
     * user-driven action; agents can never reach it. Enabling is allowed on
     * drafts only — superseded tasks stay dead.
     */
    public function enable(): void
    {
        if ($this->isSuperseded() || $this->isArchived()) {
            throw new \LogicException('Cannot enable a superseded or archived task.');
        }
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function getCreatedBy(): TaskAuthor
    {
        return $this->createdBy;
    }

    public function getReplacementFor(): ?self
    {
        return $this->replacementFor;
    }

    public function getSupersededBy(): ?self
    {
        return $this->supersededBy;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function isSuperseded(): bool
    {
        return null !== $this->supersededBy;
    }

    /** An editable, not-yet-enabled, not-archived task. */
    public function isDraft(): bool
    {
        return !$this->enabled && null === $this->archivedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Replacement semantics (SPEC §4.4): an update to enabled task A creates
     * draft B with replacement_for = A. A keeps running, untouched.
     */
    public function createReplacementDraft(TaskAuthor $author): self
    {
        $replacement = new self(
            $this->title,
            $this->brief,
            $this->kind,
            $this->toolboxMode,
            $this->toolbox,
            $author,
        );
        $replacement->schedule = $this->schedule;
        $replacement->replacementFor = $this;
        $replacement->enabled = false;

        return $replacement;
    }

    /**
     * Approve this replacement draft against its original (SPEC §4.4): in one
     * logical transaction — B is enabled, A is disabled and archived with
     * superseded_by = B. Callers must flush atomically. Throws if this task
     * is not a replacement draft.
     */
    public function approve(): void
    {
        $original = $this->replacementFor;
        if (null === $original) {
            throw new \LogicException('Cannot approve a task that is not a replacement draft.');
        }
        if ($original->isSuperseded()) {
            throw new \LogicException('Cannot approve a replacement whose original is already superseded.');
        }
        if ($this->isArchived()) {
            throw new \LogicException('Cannot approve an archived replacement draft.');
        }

        $this->enabled = true;
        $original->disable();
        $original->supersededBy = $this;
        $original->archivedAt = new \DateTimeImmutable();
    }

    /**
     * Reject this replacement draft: archive it; the original is unaffected.
     */
    public function reject(): void
    {
        if (null === $this->replacementFor) {
            throw new \LogicException('Cannot reject a task that is not a replacement draft.');
        }

        $this->archivedAt = new \DateTimeImmutable();
    }

    /**
     * Content mutation guard (SPEC §4.4): enabled tasks are immutable — an
     * enabled task only ever receives lifecycle flags.
     */
    private function assertMutable(): void
    {
        if ($this->enabled) {
            throw new \LogicException('Enabled tasks are immutable: create a replacement draft instead (SPEC §4.4).');
        }
    }
}
