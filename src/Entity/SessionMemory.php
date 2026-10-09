<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SessionMemoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of a session's memory (docs/design/SESSION_TASKS.md §3) — the
 * aggregate that carries a session's state across slices, and the head's
 * newest never-pruned tenant.
 *
 * Two shapes live here, told apart by `kind`:
 *
 * - **the objective** — singular: the current "what it is trying to achieve".
 *   Always injected, never aged, never dropped; changes by replacement, with
 *   the superseded text kept in {@see SessionMemoryRevision}.
 * - **the notes** — plural and accumulating: "where things are". Written
 *   mid-work, injected by tier (hot), capped by count.
 *
 * The session IS the namespace (§3.1): there is no key. A key namespaces a
 * store that serves many topics; this store serves one session, so the task
 * is the whole dimension. Rows are written only by {@see App\Session\SessionMemoryStore}
 * — the tools (step 3) and the UI (step 6) both go through it.
 *
 * `source` is a typed column rather than a sentence in the text (§6.3):
 * there are two writers, and their text is not the same thing — operator
 * entries are directives, session entries are recollections the model is
 * told not to follow. `revision` bumps on every write, and each replaced
 * text is appended to the revision history, so steering is visible and
 * reversible.
 */
#[ORM\Entity(repositoryClass: SessionMemoryRepository::class)]
#[ORM\Index(name: 'idx_session_memory_task_kind', columns: ['task_id', 'kind'])]
class SessionMemory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    /** The session this memory belongs to — the namespace (§3.1). */
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 16, enumType: SessionMemoryKind::class)]
    private SessionMemoryKind $kind;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(length: 16, enumType: SessionMemorySource::class)]
    private SessionMemorySource $source;

    /**
     * Null for the objective — it is always injected and is not in the tier
     * system (§3.2). Notes are born hot; ageing demotes them.
     */
    #[ORM\Column(length: 16, enumType: SessionMemoryTier::class, nullable: true)]
    private ?SessionMemoryTier $tier = null;

    /**
     * Notes only: hot and exempt from ageing (operator-set), until unpinned.
     * A pinned note is skipped by the demotion pass, so the operator keeps it
     * hot explicitly. (Pins beyond the hot cap are the operator's explicit
     * choice; the rendered block's percentage backstop still bounds the
     * request — §3.2, §3.3.).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $pinned = false;

    /** Bumped on every write; superseded texts are kept in the history. */
    #[ORM\Column(options: ['default' => 1])]
    private int $revision = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Task $task,
        SessionMemoryKind $kind,
        string $text,
        SessionMemorySource $source,
    ) {
        $this->task = $task;
        $this->kind = $kind;
        $this->text = $text;
        $this->source = $source;
        $this->tier = match ($kind) {
            SessionMemoryKind::Note => SessionMemoryTier::Hot,
            SessionMemoryKind::Objective => null,
        };

        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getKind(): SessionMemoryKind
    {
        return $this->kind;
    }

    public function isObjective(): bool
    {
        return SessionMemoryKind::Objective === $this->kind;
    }

    public function isNote(): bool
    {
        return SessionMemoryKind::Note === $this->kind;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSource(): SessionMemorySource
    {
        return $this->source;
    }

    public function getTier(): ?SessionMemoryTier
    {
        return $this->tier;
    }

    public function isHot(): bool
    {
        return SessionMemoryTier::Hot === $this->tier;
    }

    public function isCold(): bool
    {
        return SessionMemoryTier::Cold === $this->tier;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Replace this row's text and provenance, bumping the revision. The
     * caller ({@see App\Session\SessionMemoryStore}) has already appended the
     * superseded text to the history — replacement is always recorded there,
     * §6.3.
     */
    public function applyText(string $text, SessionMemorySource $source): void
    {
        $this->text = $text;
        $this->source = $source;
        ++$this->revision;
        $this->touch();
    }

    /**
     * Pin a note hot (§3.2): exempt from ageing until unpinned, and dragged
     * hot if it was cold — "pin a note hot" is one operator act, not two.
     */
    public function pin(): void
    {
        $this->assertNote('pinned');
        $this->pinned = true;
        $this->tier = SessionMemoryTier::Hot;
    }

    public function unpin(): void
    {
        $this->assertNote('unpinned');
        $this->pinned = false;
    }

    /**
     * Move a note to cold — the same transition ageing applies, made
     * explicit by the operator (§6.1).
     */
    public function demote(): void
    {
        $this->assertNote('demoted');
        if ($this->pinned) {
            throw new \LogicException('A pinned note cannot be demoted — unpin it first; pinned means kept hot.');
        }
        if ($this->isCold()) {
            throw new \LogicException('This note is already cold.');
        }

        $this->tier = SessionMemoryTier::Cold;
    }

    /**
     * Bring a cold note back to hot (§6.1). Deliberately does not touch the
     * hot cap: promotion is an explicit operator act, like a pin — the next
     * ageing pass reconciles the cap, the act itself is not silently undone.
     */
    public function promote(): void
    {
        $this->assertNote('promoted');
        if ($this->isHot()) {
            throw new \LogicException('This note is already hot.');
        }

        $this->tier = SessionMemoryTier::Hot;
    }

    private function assertNote(string $verb): void
    {
        if (!$this->isNote()) {
            throw new \LogicException(\sprintf('The objective cannot be %s — it is always injected and is not in the tier system.', $verb));
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
