<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The being: the one thing a stream belongs to (docs/design/THE_STREAM.md §2.2).
 *
 * ## Why this row exists
 *
 * The stream was built in step 1 with no owner, because at the time there was
 * nothing to point at — one deployment, one agent, one global table. That was
 * fine while every fact about her was derivable from the rows. Step 3 needs to
 * persist two facts that are *not* derivable from the transcript because they
 * describe how the transcript is rendered rather than what it says:
 *
 * - **`contextBase`** — the trim watermark. It says which exchange the retained
 *   window begins at. It used to live in `Run::$checkpoint`, so it died with
 *   the run, which is why the batch shedding never actually amortised across a
 *   session: every new run started with no watermark and re-derived one. A
 *   watermark is a fact about *her attention*, not about one run, so a run
 *   boundary must not reset it.
 * - **`promptHead`** — see {@see heads}. Compiled once and reused.
 *
 * `Grounding` sits here for the same reason as `Task::groundingAt`: a head that
 * renders the live clock is a brand-new head on every compile, so nothing ever
 * shares a prefix. One anchor per being, moved when the day turns.
 *
 * ## Why `heads` is a map and not a pair of columns
 *
 * The head is not one text. A session's head frames the completion contract as
 * a declaration, an ordinary run's frames it as a deliverable, and both belong
 * to the same being depending on which task she is running. Freezing "the" head
 * would mean freezing whichever one happened to run first and serving it to the
 * rest — a constitution leaking between tasks, which is exactly what SPEC §4.1
 * forbids.
 *
 * So it is a map from a **head key** to a compiled `{system, user}`, written on
 * first use and never rewritten. The key carries the task and the kind of head
 * ({@see \App\RunEngine\BeingStore::headKey()}), because those are what the
 * text is a function of. Step children are deliberately absent from this map:
 * their heads embed the outputs of the steps they depend on, so a frozen head
 * would freeze stale inputs (THE_STREAM.md §2.7).
 *
 * ## One being, one turn
 *
 * A being runs one task at a time. That is what makes a single head keyed only
 * by task and kind coherent: there is no second task racing for the same entry.
 * It is not an extra constraint invented here — §2.5 describes a being that
 * turns, finishes, and turns again, which is a sequence by construction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'being')]
class Being
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    /**
     * The instant this being's grounding block is rendered as of, or null when
     * it has never been anchored — in which case `createdAt` is the anchor
     * ({@see groundingInstant()}). Moved only when the deployment day turns, so
     * within a day every head compiles byte-identically
     * (docs/design/KV_CACHE.md §6.3, B4).
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $groundingAt = null;

    /**
     * The trim watermark: the index, into the exchange list the window was
     * given, at which the retained window currently begins (null before the
     * first forced trim).
     *
     * Index rather than a `seq` on purpose: the window counts exchanges, not
     * rows, and one exchange is one assistant turn plus its results. A `seq`
     * would need translating back into an exchange position on every read.
     *
     * Because it is now persisted, the batching in
     * {@see \App\Context\ShedPlan} finally amortises: the front stands still
     * across run boundaries, so the prefix the server has already prefilled
     * survives a settle.
     */
    #[ORM\Column(nullable: true)]
    private ?int $contextBase = null;

    /**
     * Whether the next trim should shed a whole batch down to the headroom
     * target rather than stopping at "just fits" — the companion flag to
     * `contextBase`, and persisted with it for the same reason.
     */
    #[ORM\Column]
    private bool $contextBatch = false;

    /**
     * Compiled heads, keyed by head key.
     *
     * Keys are optional: this column is JSON, so a row written by an older
     * build (or edited by hand) may be missing one, and reading past that
     * would throw rather than recompile.
     *
     * @var array<string, array{system?: string, user?: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $heads = [];

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroundingAt(): ?\DateTimeImmutable
    {
        return $this->groundingAt;
    }

    /**
     * The instant to render grounding as of: the stored anchor, else creation
     * time. Null only for an entity that is neither persisted nor anchored.
     */
    public function groundingInstant(): ?\DateTimeImmutable
    {
        return $this->groundingAt ?? $this->createdAt;
    }

    /** @internal moved only when the deployment day turns; see BeingStore */
    public function regroundAt(\DateTimeImmutable $instant): void
    {
        $this->groundingAt = $instant;
    }

    public function getContextBase(): ?int
    {
        return $this->contextBase;
    }

    /** @internal the trim watermark advances only through BeingStore */
    public function setContextBase(?int $base): void
    {
        $this->contextBase = $base;
    }

    public function isContextBatch(): bool
    {
        return $this->contextBatch;
    }

    /** @internal the batch flag advances only through BeingStore */
    public function setContextBatch(bool $batch): void
    {
        $this->contextBatch = $batch;
    }

    /**
     * The frozen head for `$key`, or null when it has never been compiled.
     *
     * @return array{system: string, user: string}|null
     */
    public function head(string $key): ?array
    {
        $head = $this->heads[$key] ?? null;

        if (!\is_array($head) || !isset($head['system'], $head['user'])) {
            return null;
        }

        return ['system' => (string) $head['system'], 'user' => (string) $head['user']];
    }

    /**
     * @param array{system: string, user: string} $head
     *
     * @internal a head is written once, on first use, and never rewritten
     */
    public function rememberHead(string $key, array $head): void
    {
        $this->heads[$key] = $head;
    }

    /**
     * Drop every frozen head, so the next turn recompiles.
     *
     * @internal paired with the watermark reset; see BeingStore::reset()
     */
    public function forgetHeads(): void
    {
        $this->heads = [];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
