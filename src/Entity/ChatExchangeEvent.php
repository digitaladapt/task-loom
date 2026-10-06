<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatExchangeEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row in a chat exchange's ledger (SPEC §15) — the run-event-analog.
 *
 * The ledger row's layout is inherited from `LedgerEvent`, so this table and
 * `run_event` are identical by compiler rather than by convention. What is
 * added here is the part that is a *safety invariant* rather than a payload:
 * the attribution columns.
 *
 * ## Why attribution is typed columns and not a payload entry
 *
 * `speaker`, `role`, `origin`, `content` and `reply_to_id` are real columns,
 * nullable, populated for the conversational types (`message` and `reply`)
 * and left null for the machinery (`llm_request`, `checkpoint`, `failure`),
 * which uses `payload` exactly as `RunEvent` does. The reason is in the
 * design note's §2.1: every turn carries its speaker *and the model sees it*,
 * because a flattened two-party transcript is a prompt-injection surface
 * grown inside the assistant's own history. A safety invariant does not live
 * in a JSON blob — not because JSON is slow, but because a blob has no
 * schema, so nothing enforces that the field is present, and a reader has to
 * know the convention to find it.
 *
 * There is a second, quieter consequence the design note calls out: because
 * those columns are typed, **the transcript is a plain indexed read** rather
 * than a scan of every payload in the table.
 */
#[ORM\Entity(repositoryClass: ChatExchangeEventRepository::class)]
#[ORM\Index(name: 'idx_chat_exchange_event_exchange_seq', columns: ['exchange_id', 'seq'])]
#[ORM\HasLifecycleCallbacks]
class ChatExchangeEvent extends LedgerEvent
{
    #[ORM\ManyToOne(targetEntity: ChatExchange::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'exchange_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ChatExchange $exchange = null;

    /** @see ChatEventType */
    #[ORM\Column(length: 32, enumType: ChatEventType::class)]
    private ChatEventType $type;

    /**
     * Who said it. An id validated against the roster, not a display name:
     * the name is the roster's business, and storing it here would let the
     * two drift.
     */
    #[ORM\Column(length: 32, nullable: true, enumType: Participant::class)]
    private ?Participant $speaker = null;

    /**
     * Which side of the conversation the model sees it on (SPEC §15, §2.3).
     *
     * Denormalized deliberately: a historical turn keeps the role it was
     * rendered with, so a transcript renders without re-deriving the roster
     * and a roster change cannot retroactively re-attribute past turns.
     */
    #[ORM\Column(length: 32, nullable: true, enumType: TurnRole::class)]
    private ?TurnRole $role = null;

    /** The surface it came through (config-resolved, never a transport id). */
    #[ORM\Column(length: 16, nullable: true, enumType: ChatOrigin::class)]
    private ?ChatOrigin $origin = null;

    /** What was said. Null on the machinery rows. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    /**
     * Intra-conversation threading: the event this turn replies to, or null
     * when it starts a thread.
     *
     * The only correlation field that survived the surface decision — an
     * earlier draft carried a `transport_ref` holding a delivery transport's
     * own message id, which presumed the conversation lives inside a
     * transport's id space (SPEC §15, and §9 of the design note for why that
     * was a mistake).
     */
    #[ORM\Column(nullable: true)]
    private ?int $replyToId = null;

    public function __construct(ChatEventType $type)
    {
        $this->type = $type;
    }

    /**
     * A conversational turn: something a participant said.
     *
     * A named constructor rather than a public setter chain, because the
     * attribution fields are a set that is either all present or all absent,
     * and "half-attributed turn" is precisely the state §2.1 forbids. Building
     * it in one call makes that unrepresentable; the six-argument signature is
     * the cost, and it is worth paying once.
     */
    public static function turn(
        ChatEventType $type,
        Participant $speaker,
        TurnRole $role,
        ChatOrigin $origin,
        string $content,
        ?int $replyToId = null,
    ): self {
        if (!$type->isConversational()) {
            throw new \InvalidArgumentException(\sprintf('Only a conversational event type can be built as a turn; %s is machinery (SPEC §15).', $type->value));
        }

        $event = new self($type);
        $event->speaker = $speaker;
        $event->role = $role;
        $event->origin = $origin;
        $event->content = $content;
        $event->replyToId = $replyToId;

        return $event;
    }

    public function getExchange(): ?ChatExchange
    {
        return $this->exchange;
    }

    /** @internal assigned by ChatExchange::appendEvent() */
    public function assignToExchange(ChatExchange $exchange, int $seq): void
    {
        $this->exchange = $exchange;
        $this->assignSeq($seq);
    }

    public function getType(): ChatEventType
    {
        return $this->type;
    }

    public function getSpeaker(): ?Participant
    {
        return $this->speaker;
    }

    public function getRole(): ?TurnRole
    {
        return $this->role;
    }

    public function getOrigin(): ?ChatOrigin
    {
        return $this->origin;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getReplyToId(): ?int
    {
        return $this->replyToId;
    }

    /**
     * The turn as the model sees it: the role mapping applied, content
     * verbatim. This is the one place the stored record becomes prompt, which
     * is why the role is read from the column rather than recomputed.
     *
     * @return array{role: string, content: string}
     */
    public function toMessage(): array
    {
        if (null === $this->role || null === $this->content) {
            throw new \LogicException(\sprintf('A %s event is not a turn and cannot become a message (SPEC §15).', $this->type->value));
        }

        return ['role' => $this->role->value, 'content' => $this->content];
    }
}
