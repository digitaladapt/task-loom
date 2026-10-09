<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The text a session-memory edit replaced (docs/design/SESSION_TASKS.md
 * §6.3) — append-only, one row per superseded text.
 *
 * It exists for one reason: steering must be **visible and reversible**.
 * The objective is "last writer wins, visibly" — a session that supersedes
 * an operator's steer is seen doing it, not caught doing it — and every
 * write keeps the text it replaced, so any steer can be inspected (and, in
 * the UI, reverted) afterwards. Same instinct as SPEC §4.4's "replacement,
 * not mutation", applied to the one thing that must stay mutable.
 *
 * `revision` is the revision the superseded text belonged to — the current
 * text lives on the {@see SessionMemory} row at `revision + 1`.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_session_memory_revision_memory', columns: ['memory_id'])]
class SessionMemoryRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    /** The memory row whose text this was. */
    #[ORM\ManyToOne(targetEntity: SessionMemory::class)]
    #[ORM\JoinColumn(name: 'memory_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SessionMemory $memory;

    /** The revision the superseded text belonged to. */
    #[ORM\Column]
    private int $revision;

    #[ORM\Column(type: 'text')]
    private string $text;

    /** Who had written the superseded text — provenance is kept with it. */
    #[ORM\Column(length: 16, enumType: SessionMemorySource::class)]
    private SessionMemorySource $source;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $savedAt;

    public function __construct(
        SessionMemory $memory,
        int $revision,
        string $text,
        SessionMemorySource $source,
    ) {
        $this->memory = $memory;
        $this->revision = $revision;
        $this->text = $text;
        $this->source = $source;
        $this->savedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMemory(): SessionMemory
    {
        return $this->memory;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSource(): SessionMemorySource
    {
        return $this->source;
    }

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }
}
