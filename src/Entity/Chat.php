<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A durable conversation (SPEC §15) — the thing being worked on, the
 * task-analog.
 *
 * Three nouns, and this is the first: `Chat` is the conversation,
 * `ChatExchange` is one execution over it, `ChatExchangeEvent` is one row in
 * that execution's ledger. A turn is *not* a fourth noun — the transcript is
 * a filtered read over the events (SPEC §15, and the design note's decision:
 * a separate turn table would duplicate rows that already exist).
 *
 * ## A conversation does not end
 *
 * This is the property that distinguishes it from a task, and it is a
 * deliberate cost rather than an oversight: a `Run` is bounded by
 * construction because it terminates, so its context is bounded too. A chat
 * grows forever, which means the *context* policy is the open problem (the
 * design note's §10.2) — not the storage, since the ledger keeps everything.
 * Compaction is not built here; nothing in this version hides that.
 *
 * ## Private, and purged locally
 *
 * The second consequence of not being a `Run`: transcripts are long-lived and
 * personal, and deleting one must not reach into the run graph. There is no
 * cascade from here to `Task`, `Run` or `ToolCall` — the only cascade is
 * `Chat → ChatExchange → ChatExchangeEvent`, all of it local.
 */
#[ORM\Entity(repositoryClass: ChatRepository::class)]
class Chat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * The instant this conversation's grounding is fixed to, or null when the
     * conversation has never been re-grounded — in which case its creation
     * time is the anchor ({@see groundingInstant()}).
     *
     * A conversation is grounded in the day it is resumed on, so chat
     * deliberately re-renders the clock (`ChatEngine::compileSystemMessage`).
     * But a clock that moves sits at position 0 of the system message, and a
     * prefix-caching server reuses a request only up to the first byte that
     * changed — so a live clock makes every reply re-prefill the entire
     * conversation (measured: 28% reuse against 94% when it does not move; see
     * `docs/design/KV_CACHE_POSITION_FINDINGS.md`).
     *
     * Recording an instant *freezes* the block instead: the engine renders
     * grounding as of this moment, so it is byte-identical on every request
     * while the deployment day holds, and the conversation keeps its whole
     * prefix. When the day turns, the anchor is moved (see
     * `App\Chat\ChatEngine`), which costs one cache break a day rather than
     * one a minute.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $groundingAt = null;

    /**
     * The exchanges, in creation order.
     *
     * No `#[ORM\OrderBy]` here, deliberately: the attribute takes a *string*
     * direction, which is the deprecated form the query builders were all
     * migrated off (and which `tests/Doctrine/QueryBuilderOrderingTest.php`
     * exists to keep out). Ordering belongs to the repositories, which have
     * the enum; `ChatExchangeRepository::findForChat()` is the ordered read.
     *
     * @var Collection<int, ChatExchange>
     */
    #[ORM\OneToMany(mappedBy: 'chat', targetEntity: ChatExchange::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $exchanges;

    public function __construct(string $title)
    {
        $this->title = self::normalizeTitle($title);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->exchanges = new ArrayCollection();
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
        $this->title = self::normalizeTitle($title);
        $this->touch();
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
     * The clock this conversation's grounding is fixed to, resolving for a row
     * written before the anchor existed.
     *
     * A conversation is never ungrounded, so "no anchor yet" answers with the
     * conversation's own creation time rather than `null`-means-now: a stored
     * conversation must render the same block on every request, and reading the
     * live clock here would put the moving value back on the wire for exactly
     * the rows that cannot yet be migrated. The first reply to an old
     * conversation therefore grounds it once, in its own past — grounded, and
     * then frozen.
     */
    public function groundingInstant(): \DateTimeImmutable
    {
        return $this->groundingAt ?? $this->createdAt;
    }

    /**
     * Move the grounding anchor to a new instant.
     *
     * Called when the deployment day has turned (or on an explicit re-ground):
     * the next request renders the new block, and every request after it is
     * byte-stable again until the day turns once more.
     */
    public function regroundAt(\DateTimeImmutable $instant): void
    {
        $this->groundingAt = $instant;
    }

    /** @return Collection<int, ChatExchange> */
    public function getExchanges(): Collection
    {
        return $this->exchanges;
    }

    public function appendExchange(ChatExchange $exchange): ChatExchange
    {
        $this->exchanges->add($exchange);
        $this->touch();

        return $exchange;
    }

    /**
     * Mark the conversation as having moved.
     *
     * Called when a turn is appended rather than on every write, so the
     * timestamp answers "when was anything last said here?" — which is the
     * question a list of conversations is actually sorted by.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * A conversation always has a title — it is how you find it again later.
     * Messages are not required to be thoughtful prose, so the title is
     * derived when the human does not supply one, and an empty title is
     * refused rather than stored as an unnameable row.
     */
    private static function normalizeTitle(string $title): string
    {
        $title = trim($title);

        if ('' === $title) {
            throw new \InvalidArgumentException('A conversation needs a title.');
        }

        return mb_substr($title, 0, 200);
    }
}
