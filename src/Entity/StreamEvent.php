<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row of the being's stream (docs/design/THE_STREAM.md §2.1).
 *
 * ## What this replaces
 *
 * Continuity used to live in two places that were both wrong for it: the
 * per-run checkpoint's `exchanges` array, which died with the run, and the
 * `## Memories` block, which existed *because* the context could not persist.
 * Under one stream neither has a job. A turn is a row here; the window is a
 * view onto the tail; a slice boundary is invisible.
 *
 * ## Why the shape is flat
 *
 * `Role` and `content` are columns rather than a payload, because the wire
 * protocol is the reason this table exists and the protocol's notion of a
 * message *is* `{role, content, tool_calls, tool_call_id}`. A JSON blob would
 * make every read a decode and every ordering question a guess about an array
 * inside it. What stays in `tool_calls`/`tool_results` is what the protocol
 * itself nests: an assistant turn's calls, and the results answering them.
 *
 * ## Unrendered, uncapped, append-only
 *
 * `content` is exactly what was said. The cap on what the model sees now lives
 * at render time, in the window ({@see \App\Context\ContextWindow}), which is
 * only defensible *because* this table keeps the whole thing — the phrase
 * "the database still has it" has to be true for the large results most of all,
 * since those are what a later compaction turn will want to read.
 *
 * Nothing here is updated after insert. `seq` is assigned by the owning
 * aggregate's append path, never by a caller, so two writers cannot disagree
 * about the order.
 *
 * ## Why `seq` and not `created_at` for ordering
 *
 * Timestamps collide and clocks move backwards. Order is a promise the stream
 * makes about causality — a tool result never precedes its call — and only a
 * contiguous integer assigned under the same lock can keep it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stream_event')]
#[ORM\UniqueConstraint(name: 'UNIQ_STREAM_EVENT_BEING_SEQ', columns: ['being_id', 'seq'])]
#[ORM\Index(name: 'IDX_STREAM_EVENT_RUN', columns: ['run_id'])]
#[ORM\Index(name: 'IDX_STREAM_EVENT_EXCHANGE', columns: ['exchange_id'])]
class StreamEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    /**
     * The stream this row belongs to (THE_STREAM.md §2.2).
     *
     * Ownership, unlike `run` and `exchange` below, which are provenance. A row
     * without a being is not addressable: `seq` is only meaningful inside one
     * being's number line, which is why the uniqueness constraint is
     * `(being_id, seq)` rather than `seq`.
     *
     * `CASCADE` where the provenance columns use `SET NULL`: deleting a being
     * deletes her stream, because a stream is what a being is. There is no
     * version of this row that means anything with the being gone, whereas a
     * row whose *run* is gone is still a turn she had.
     */
    #[ORM\ManyToOne(targetEntity: Being::class)]
    #[ORM\JoinColumn(name: 'being_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Being $being = null;

    /**
     * Position in the stream. Strictly increasing, contiguous, assigned on
     * append. This is the column the whole design orders by.
     */
    #[ORM\Column]
    private int $seq = 0;

    /** Who wrote it — the human, her, or the heartbeat. */
    #[ORM\Column(length: 16, enumType: StreamAuthor::class)]
    private StreamAuthor $author;

    /** Which side of the conversation it renders on. */
    #[ORM\Column(length: 16, enumType: StreamRole::class)]
    private StreamRole $role;

    /** Unrendered, uncapped text. Empty string when the turn was calls only. */
    #[ORM\Column(type: 'text')]
    private string $content = '';

    /**
     * The assistant turn's calls, verbatim, in the protocol's own shape.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $toolCalls = [];

    /**
     * The results answering those calls, verbatim and uncapped.
     *
     * A list rather than a map so a result keeps its position in the round it
     * answers; `tool_call_id` inside each entry is what pairs it to a call.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $toolResults = [];

    /**
     * The run that wrote this row, when a run did.
     *
     * Provenance, not ownership: the stream outlives any run, and a run's
     * deletion must not take her memory with it. Hence `SET NULL` — the row
     * stays, it just stops being attributable to a run that no longer exists.
     * Null for a chat exchange's rows until chat writes through the stream.
     */
    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Run $run = null;

    /**
     * The chat exchange that wrote this row, when one did.
     *
     * The same provenance-not-ownership rule as `run`, and for the same
     * reason: a deleted conversation must not silently rewrite her history.
     */
    #[ORM\ManyToOne(targetEntity: ChatExchange::class)]
    #[ORM\JoinColumn(name: 'exchange_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatExchange $exchange = null;

    /** When it happened. Display order comes from `seq`, never from this. */
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @internal the only way a row is created; see StreamWriter */
    public function __construct(StreamAuthor $author, StreamRole $role)
    {
        $this->author = $author;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBeing(): ?Being
    {
        return $this->being;
    }

    /** @internal assigned on append, before the seq it scopes */
    public function assignToBeing(Being $being): void
    {
        $this->being = $being;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    /** @internal assigned once, on append */
    public function assignSeq(int $seq): void
    {
        $this->seq = $seq;
    }

    public function getAuthor(): StreamAuthor
    {
        return $this->author;
    }

    public function getRole(): StreamRole
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /** @param list<array<string, mixed>> $calls */
    public function setToolCalls(array $calls): void
    {
        $this->toolCalls = $calls;
    }

    /** @return list<array<string, mixed>> */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /** @param list<array<string, mixed>> $results */
    public function setToolResults(array $results): void
    {
        $this->toolResults = $results;
    }

    /** @return list<array<string, mixed>> */
    public function getToolResults(): array
    {
        return $this->toolResults;
    }

    public function getRun(): ?Run
    {
        return $this->run;
    }

    /** @internal assigned on append, alongside the seq */
    public function assignToRun(?Run $run): void
    {
        $this->run = $run;
    }

    public function getExchange(): ?ChatExchange
    {
        return $this->exchange;
    }

    /** @internal assigned on append, alongside the seq */
    public function assignToExchange(?ChatExchange $exchange): void
    {
        $this->exchange = $exchange;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
