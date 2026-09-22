<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ToolCallRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dispatched tool call (SPEC §7). Attached to its RunEvent row —
 * the ledger's tool_call / tool_result pair shares the payload here.
 *
 * `server` is the MCP server name from the run's toolbox snapshot (the
 * model never sees server URLs — SPEC §4.1).
 */
#[ORM\Entity(repositoryClass: ToolCallRepository::class)]
#[ORM\Index(name: 'idx_tool_call_run_event', columns: ['run_event_id'])]
class ToolCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\ManyToOne(targetEntity: RunEvent::class, inversedBy: 'toolCalls')]
    #[ORM\JoinColumn(name: 'run_event_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?RunEvent $runEvent = null;

    /** Tool name, as the model saw it in the toolbox. */
    #[ORM\Column(length: 128)]
    private string $tool;

    /** Server name from the snapshot — never a URL. */
    #[ORM\Column(length: 64)]
    private string $server;

    /**
     * Arguments as dispatched (already schema-validated in the harness).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $arguments = [];

    /**
     * Result payload: tool output or error detail. Capped/truncated with an
     * explicit marker before storage, per SPEC §4.2.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $result = [];

    /** Error taxonomy class when the call failed (SPEC §5.3). */
    #[ORM\Column(length: 32, nullable: true, enumType: ErrorClass::class)]
    private ?ErrorClass $errorClass = null;

    /** Retry attempt number (1-based). */
    #[ORM\Column]
    private int $attemptNo = 1;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(string $tool, string $server, array $arguments = [])
    {
        $this->tool = $tool;
        $this->server = $server;
        $this->arguments = $arguments;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunEvent(): ?RunEvent
    {
        return $this->runEvent;
    }

    /** @internal assigned by RunEvent::attachToolCall() */
    public function assignToRunEvent(RunEvent $runEvent): void
    {
        $this->runEvent = $runEvent;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getServer(): string
    {
        return $this->server;
    }

    /** @return array<string, mixed> */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, mixed> */
    public function getResult(): array
    {
        return $this->result;
    }

    /** @param array<string, mixed> $result */
    public function setResult(array $result): void
    {
        $this->result = $result;
    }

    public function getErrorClass(): ?ErrorClass
    {
        return $this->errorClass;
    }

    public function setErrorClass(?ErrorClass $errorClass): void
    {
        $this->errorClass = $errorClass;
    }

    public function getAttemptNo(): int
    {
        return $this->attemptNo;
    }

    public function setAttemptNo(int $attemptNo): void
    {
        $this->attemptNo = $attemptNo;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): void
    {
        $this->durationMs = $durationMs;
    }
}
