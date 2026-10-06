<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatExchangeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One execution over a conversation (SPEC §15) — the run-analog.
 *
 * ## What an exchange is
 *
 * An inbound message is the trigger; everything from that message until the
 * reply concludes is one exchange; each thing that happens inside it is one
 * typed event (`ChatExchangeEvent`). So an exchange is *a run-shaped
 * execution over a conversation instead of a task* — the same idea applied to
 * a different domain object, not a `Run` and not a `Task`
 * (`docs/design/CHAT_AND_CAPACITY.md` §3.1, §3.2).
 *
 * ## Why it is not a `Run`
 *
 * The design note argues this against the code rather than from taste, and
 * the argument is worth keeping next to the class: `Run.task_id` is NOT NULL
 * and `Run::__construct()` takes a `Task`, so reuse would mean a synthetic
 * "Internal: Chat" task row — a lie at the centre of the schema, appearing in
 * the task admin UI and in `findLatestForTasks`. Worse, `findRecent()` and
 * `findAttention()` filter on `parent IS NULL` and nothing else, so a chat
 * exchange **would** surface in the run history and in the attention queue —
 * the queue whose entire job is "a run needs a human". A chat is not a run
 * that needs attention, and those filters fail *silently*.
 *
 * ## What it does share
 *
 * The machinery, not the tables (§3.3): the claim protocol
 * (`App\Claims\ClaimStore`), the checkpoint-and-resume contract, and the
 * ledger row's layout (`LedgerEvent`). The state machine is deliberately its
 * own — `ChatExchangeStatus` is four states, not seven, because a
 * conversation never declares justified completion.
 */
#[ORM\Entity(repositoryClass: ChatExchangeRepository::class)]
#[ORM\Index(name: 'idx_chat_exchange_chat', columns: ['chat_id'])]
#[ORM\Index(name: 'idx_chat_exchange_status', columns: ['status'])]
class ChatExchange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\ManyToOne(targetEntity: Chat::class, inversedBy: 'exchanges')]
    #[ORM\JoinColumn(name: 'chat_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Chat $chat;

    /**
     * What triggered this exchange. `inbound` is the only value v1 writes —
     * v1 is respond-only — and the field exists now so that initiation later
     * is an addition rather than a redesign (see `ChatTrigger`).
     */
    #[ORM\Column(length: 16, enumType: ChatTrigger::class, options: ['default' => 'inbound'])]
    private ChatTrigger $triggeredBy = ChatTrigger::Inbound;

    /** @see ChatExchangeStatus */
    #[ORM\Column(length: 16, enumType: ChatExchangeStatus::class)]
    private ChatExchangeStatus $status = ChatExchangeStatus::Queued;

    /**
     * Last persisted position — crash/resume walks from here, exactly as a
     * run does. Null until the first checkpoint.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $checkpoint = null;

    /**
     * The toolbox the human chose for this exchange (SPEC §15,
     * `docs/design/CHAT_TOOLS.md`): the mode plus the tags or tool names they
     * picked. Null means "no tools", which is also what every exchange
     * written before chat had a toolbox means — so those rows keep behaving
     * exactly as they did.
     *
     * The *declaration* is stored, not just its resolution, so the editor can
     * reopen showing what was chosen — including an entry the catalog no
     * longer carries. Resolving on read would rewrite the human's selection to
     * whatever resolves today.
     *
     * @var array{mode: string, declared: list<string>}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $toolboxDeclaration = null;

    /**
     * The resolved tools, frozen (SPEC §4.1's snapshot, in the run engine's
     * own shape). Frozen at exchange start and never re-resolved, for the
     * reason the run engine freezes a run's: the tool definitions on the wire,
     * the toolbox in the prompt, and the map dispatch consults must be one
     * set, or a tool outside the declared set has a path to dispatch.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $toolboxSnapshot = null;

    /**
     * Execution-claim token, in the run's sense exactly (SPEC §6): incremented
     * by the claim when a worker takes the exchange for one message, so a
     * duplicate delivery can detect that an owner is already at work.
     *
     * Written via raw SQL — never through the ORM — so entity flushes cannot
     * accidentally release or bump it. See `App\Claims\ClaimStore`.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $lockVersion = 0;

    /** Unix time the current owner took the claim, or null when free. */
    #[ORM\Column(nullable: true)]
    private ?int $claimedAt = null; // @phpstan-ignore property.unusedType (Doctrine hydrates the raw-SQL-written value)

    /** Which fleet took the claim, or null when the claimant had no identity. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $claimFleet = null; // @phpstan-ignore property.unusedType (Doctrine hydrates the raw-SQL-written value)

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Terminal error class, when the exchange failed (SPEC §5.3). */
    #[ORM\Column(length: 32, nullable: true, enumType: ErrorClass::class)]
    private ?ErrorClass $errorClass = null;

    /** @var Collection<int, ChatExchangeEvent> */
    #[ORM\OneToMany(mappedBy: 'exchange', targetEntity: ChatExchangeEvent::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $events;

    public function __construct(Chat $chat)
    {
        $this->chat = $chat;
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChat(): Chat
    {
        return $this->chat;
    }

    public function getTriggeredBy(): ChatTrigger
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(ChatTrigger $triggeredBy): void
    {
        $this->triggeredBy = $triggeredBy;
    }

    public function getStatus(): ChatExchangeStatus
    {
        return $this->status;
    }

    /**
     * Whether this exchange has stopped moving. Terminal for the *exchange*
     * only: a conversation is never over, and the next thing the human says
     * starts a fresh one.
     */
    public function isTerminal(): bool
    {
        return \in_array($this->status, [ChatExchangeStatus::Answered, ChatExchangeStatus::Failed], true);
    }

    /**
     * Record the toolbox this exchange runs with.
     *
     * Called once, at exchange start, inside the same transaction that records
     * the inbound turn — because the snapshot is part of what the exchange
     * *is*, not a preference read later. There is deliberately no setter that
     * can be called mid-exchange: the freeze is the safety property, so the
     * only way to change tools is to start a new exchange.
     *
     * @param array{mode: string, declared: list<string>} $declaration
     * @param list<array<string, mixed>>                  $snapshot
     */
    public function freezeToolbox(array $declaration, array $snapshot): void
    {
        $this->toolboxDeclaration = $declaration;
        $this->toolboxSnapshot = $snapshot;
    }

    /** @return array{mode: string, declared: list<string>}|null */
    public function getToolboxDeclaration(): ?array
    {
        return $this->toolboxDeclaration;
    }

    /** @return list<array<string, mixed>>|null */
    public function getToolboxSnapshot(): ?array
    {
        return $this->toolboxSnapshot;
    }

    /** @return array<string, mixed>|null */
    public function getCheckpoint(): ?array
    {
        return $this->checkpoint;
    }

    /** @param array<string, mixed>|null $checkpoint */
    public function setCheckpoint(?array $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }

    /** The claim token as of the last load — what a worker presents to prove ownership. */
    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getClaimedAt(): ?int
    {
        return $this->claimedAt;
    }

    public function getClaimFleet(): ?string
    {
        return $this->claimFleet;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getErrorClass(): ?ErrorClass
    {
        return $this->errorClass;
    }

    /** @return Collection<int, ChatExchangeEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function markStarted(): void
    {
        $this->status = ChatExchangeStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
    }

    /**
     * The reply is committed.
     *
     * Note what this is *not*: it is not a justified completion in the run
     * engine's sense (SPEC §5.4). An exchange produces a reply or it does
     * not; there is no artifact to justify and no budget to exhaust.
     */
    public function markAnswered(): void
    {
        $this->status = ChatExchangeStatus::Answered;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * The exchange could not produce a reply.
     *
     * Fails loudly in the schema as well as in the UI, because the failure
     * mode that matters here has no precedent in the run lanes: a run that
     * throws is *visible* — it lands in `failed` and the run page shows it —
     * whereas a person staring at a chat that never answers has nothing to
     * look at. A failed exchange is the record that says "someone is still
     * waiting, and here is why".
     */
    public function markFailed(ErrorClass $errorClass): void
    {
        $this->status = ChatExchangeStatus::Failed;
        $this->errorClass = $errorClass;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * Append an event to the exchange's ledger. The seq number is the event's
     * position in the exchange — assigned here, once, on append.
     */
    public function appendEvent(ChatExchangeEvent $event): ChatExchangeEvent
    {
        $event->assignToExchange($this, \count($this->events) + 1);
        $this->events->add($event);

        return $event;
    }

    /**
     * Append a machinery event — one of the things that happened *around* the
     * conversation rather than to it.
     *
     * The counterpart of `ChatExchangeEvent::turn()`, and the reason both
     * exist: a turn must carry attribution to be legal (§2.1), and a
     * machinery row must not pretend to. Two named constructors make each
     * state reachable only through the door that is correct for it, so a
     * half-attributed turn is not something a caller can accidentally build.
     *
     * @param array<string, mixed> $payload
     */
    public function appendMachinery(
        ChatEventType $type,
        array $payload = [],
        ?ErrorClass $errorClass = null,
        ?int $attemptNo = null,
        ?int $durationMs = null,
    ): ChatExchangeEvent {
        if ($type->isConversational()) {
            throw new \InvalidArgumentException(\sprintf('%s is a conversational type — build it as a turn so it carries its speaker (SPEC §15).', $type->value));
        }

        $event = new ChatExchangeEvent($type);
        $event->setPayload($payload);
        $event->setErrorClass($errorClass);
        $event->setAttemptNo($attemptNo);
        $event->setDurationMs($durationMs);

        return $this->appendEvent($event);
    }
}
