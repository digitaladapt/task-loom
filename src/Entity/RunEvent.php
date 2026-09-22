<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RunEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row in the attempt ledger (SPEC §5.3) — the centerpiece. Every LLM
 * request, tool call, validation error, retry, and completion is a typed
 * event row. Append-only: the transcript in the DB is the full, honest
 * record; only what's sent to the model is trimmed.
 */
#[ORM\Entity(repositoryClass: RunEventRepository::class)]
#[ORM\Index(name: 'idx_run_event_run_seq', columns: ['run_id', 'seq'])]
class RunEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\ManyToOne(targetEntity: Run::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Run $run = null;

    #[ORM\Column]
    private int $seq = 0;

    /** @see RunEventType */
    #[ORM\Column(length: 32, enumType: RunEventType::class)]
    private RunEventType $type;

    /** When the event happened. */
    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Typed event payload (decoded JSON). Structure depends on type.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /** Fixed error taxonomy class, when applicable (SPEC §5.3). */
    #[ORM\Column(length: 32, nullable: true, enumType: ErrorClass::class)]
    private ?ErrorClass $errorClass = null;

    /** Retry attempt number (1-based) for tool calls, when applicable. */
    #[ORM\Column(nullable: true)]
    private ?int $attemptNo = null;

    /** Duration of the underlying operation (tool call / LLM request), ms. */
    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** @var Collection<int, ToolCall> */
    #[ORM\OneToMany(mappedBy: 'runEvent', targetEntity: ToolCall::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $toolCalls;

    public function __construct(RunEventType $type)
    {
        $this->type = $type;
        $this->toolCalls = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): ?Run
    {
        return $this->run;
    }

    /** @internal assigned by Run::appendEvent() */
    public function assignToRun(Run $run, int $seq): void
    {
        $this->run = $run;
        $this->seq = $seq;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function getType(): RunEventType
    {
        return $this->type;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function setErrorClass(?ErrorClass $errorClass): void
    {
        $this->errorClass = $errorClass;
    }

    public function setAttemptNo(?int $attemptNo): void
    {
        $this->attemptNo = $attemptNo;
    }

    public function setDurationMs(?int $durationMs): void
    {
        $this->durationMs = $durationMs;
    }

    public function getErrorClass(): ?ErrorClass
    {
        return $this->errorClass;
    }

    public function getAttemptNo(): ?int
    {
        return $this->attemptNo;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** @return Collection<int, ToolCall> */
    public function getToolCalls(): Collection
    {
        return $this->toolCalls;
    }

    public function attachToolCall(ToolCall $call): void
    {
        $call->assignToRunEvent($this);
        $this->toolCalls->add($call);
    }
}
