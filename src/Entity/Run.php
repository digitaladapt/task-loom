<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One execution of a task (SPEC §7).
 *
 * `toolbox_snapshot` freezes the resolved tools at run start (SPEC §4.1):
 * the audit trail of exactly what the model could see — useful for security
 * review and for reproducing failures. The run engine never re-resolves
 * mid-run; there is no request_tool escape hatch in v1.
 */
#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Index(name: 'idx_run_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_run_status', columns: ['status'])]
class Run
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Task $task;

    /** @see RunStatus */
    #[ORM\Column(length: 32, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Queued;

    /**
     * The resolved tools, frozen at run start (SPEC §4.1): a list of
     * {server, serverUrl, protocol, tool, description, schema} tuples —
     * everything a fresh worker needs to rebuild the toolbox for a turn
     * without consulting the catalog (see App\RunEngine\ToolboxSnapshot).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $toolboxSnapshot = [];

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * Last persisted exchange — crash/resume walks from here (SPEC §5.5).
     * Null until the first checkpoint.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $checkpoint = null;

    #[ORM\Column]
    private int $stepCount = 0;

    /**
     * Execution-claim token (SPEC §6): incremented by the engine's
     * claimRun() when a worker takes ownership of a turn, so a duplicate
     * delivery (at-least-once transport, repair dispatch) can detect that
     * an owner is already at work and drop. Written via raw SQL — never
     * through the ORM — so entity flushes cannot accidentally release or
     * bump it.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $lockVersion = 0;

    /**
     * Unix time the current owner took the claim, or null when free. A
     * claim is only honored for CLAIM_STALE_SECONDS (see RunEngine): a
     * worker that dies mid-turn cannot leave the run claimed forever.
     */
    #[ORM\Column(nullable: true)]
    private ?int $claimedAt = null; // @phpstan-ignore property.unusedType (Doctrine hydrates the raw-SQL-written value)

    /** Terminal error class, when the run failed (SPEC §5.3). */
    #[ORM\Column(length: 32, nullable: true, enumType: ErrorClass::class)]
    private ?ErrorClass $errorClass = null;

    /** @var Collection<int, RunEvent> */
    #[ORM\OneToMany(mappedBy: 'run', targetEntity: RunEvent::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $events;

    public function __construct(Task $task)
    {
        $this->task = $task;
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function setStatus(RunStatus $status): void
    {
        $this->status = $status;
    }

    /** @return list<array<string, mixed>> */
    public function getToolboxSnapshot(): array
    {
        return $this->toolboxSnapshot;
    }

    /**
     * @param list<array<string, mixed>> $tools
     */
    public function setToolboxSnapshot(array $tools): void
    {
        $this->toolboxSnapshot = $tools;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function markStarted(): void
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->status = RunStatus::Running;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return array<string, mixed>|null */
    public function getCheckpoint(): ?array
    {
        return $this->checkpoint;
    }

    /**
     * @param array<string, mixed>|null $checkpoint
     */
    public function setCheckpoint(?array $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }

    public function getStepCount(): int
    {
        return $this->stepCount;
    }

    /**
     * The claim token as of the last load — what a worker presents to
     * claimRun() to prove nobody else has moved the run since (§6).
     */
    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getClaimedAt(): ?int
    {
        return $this->claimedAt;
    }

    public function incrementStepCount(): void
    {
        ++$this->stepCount;
    }

    public function getErrorClass(): ?ErrorClass
    {
        return $this->errorClass;
    }

    public function setErrorClass(ErrorClass $errorClass): void
    {
        $this->errorClass = $errorClass;
    }

    /** @return Collection<int, RunEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * Append an event to the attempt ledger (SPEC §5.3). The seq number is
     * the event's position in the run — assigned here, once, on append.
     */
    public function appendEvent(RunEvent $event): RunEvent
    {
        $event->assignToRun($this, count($this->events) + 1);
        $this->events->add($event);

        return $event;
    }

    /**
     * Terminal transition helpers (SPEC §5.4, §5.2): a run ends either with
     * a justified completion declaration or with a named failure. Never a
     * bare "step complete".
     */
    public function markSucceeded(): void
    {
        $this->status = RunStatus::Succeeded;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function markIncomplete(): void
    {
        $this->status = RunStatus::Incomplete;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function markFailed(ErrorClass $errorClass): void
    {
        $this->status = RunStatus::Failed;
        $this->errorClass = $errorClass;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function markNeedsAttention(ErrorClass $errorClass): void
    {
        $this->status = RunStatus::NeedsAttention;
        $this->errorClass = $errorClass;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function pause(): void
    {
        $this->status = RunStatus::Paused;
    }

    public function isActive(): bool
    {
        return \in_array($this->status, [RunStatus::Queued, RunStatus::Running, RunStatus::Paused], true);
    }
}
