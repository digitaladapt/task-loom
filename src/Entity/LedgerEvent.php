<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row in a typed event ledger (SPEC §5.3, §15) — the shared half of
 * `RunEvent` and `ChatExchangeEvent`.
 *
 * The design note for chat and capacity (`docs/design/CHAT_AND_CAPACITY.md`
 * §3.3) chose to duplicate the *shape* rather than reuse the run tables — a
 * chat exchange must not become a synthetic task, and a chat row must not
 * appear in the run history or the attention queue. The mitigation for the
 * duplication is to share the *machinery*, and this is one piece of it: the
 * two ledgers are layout-identical by inheritance rather than by two authors
 * remembering to keep them in step. `seq`, `created_at`, `payload`,
 * `error_class`, `attempt_no`, `duration_ms` are declared once, here.
 *
 * ## The one column deliberately not shared: `type`
 *
 * `type` is declared by each subclass against its own enum. The vocabularies
 * are deliberately two — SPEC §5.3 fixes the run vocabulary, SPEC §15 fixes
 * the chat vocabulary — and merging them into one enum would let a chat event
 * type appear on a run. Both are stored as `VARCHAR(32)`, so the *layout* is
 * still identical; only the closed set of legal values differs, which is
 * exactly the level at which the domains are allowed to differ.
 *
 * The append-only contract is the other half of §5.3 and holds here
 * unchanged: the transcript in the database is the full, honest record; only
 * what is sent to the model is trimmed.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class LedgerEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    /** Position in the owning aggregate's ledger, assigned once on append. */
    #[ORM\Column]
    private int $seq = 0;

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

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeq(): int
    {
        return $this->seq;
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

    public function getErrorClass(): ?ErrorClass
    {
        return $this->errorClass;
    }

    public function setErrorClass(?ErrorClass $errorClass): void
    {
        $this->errorClass = $errorClass;
    }

    public function getAttemptNo(): ?int
    {
        return $this->attemptNo;
    }

    public function setAttemptNo(?int $attemptNo): void
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

    /** @internal assigned by the owning aggregate's append method */
    protected function assignSeq(int $seq): void
    {
        $this->seq = $seq;
    }
}
