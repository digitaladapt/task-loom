<?php

declare(strict_types=1);

namespace App\Chat;

use App\Claims\ClaimStore;
use App\Claims\ClaimTarget;
use App\Context\Grounding;
use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Entity\ErrorClass;
use App\Entity\Participant;
use App\Llm\LlmClientInterface;
use App\Llm\LlmRequestException;
use App\Message\ChatReplyMessage;
use App\Repository\ChatExchangeEventRepository;
use App\Repository\ChatExchangeRepository;
use App\Repository\ChatRepository;
use App\RunEngine\RunEngine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The chat turn loop (SPEC §15).
 *
 * ## What this reuses, and what it refuses
 *
 * Reused: the message bus and its lanes, `LlmClientInterface`, the claim /
 * checkpoint / requeue machinery (through `App\Claims\ClaimStore`), and the
 * attempt ledger's shape. That is the whole reason chat is cheap to build —
 * "debug a chat turn like a task turn" is literally true.
 *
 * Refused: the `Task → Run → Step` graph, and `RunStatus` as the state
 * machine. A task is a two-phase engine with budgets, step DAGs, strict
 * fail-closed advancement and justified completion; a conversation is none of
 * those things. The design note's §11 draws the line: this is the *same*
 * engine applied to a second aggregate, not a new engine. If any part of this
 * needs one, the design is wrong rather than the code incomplete.
 *
 * ## The transcript is the state
 *
 * There is no separate conversation buffer. The turns are the conversational
 * rows of the conversation's exchanges, and the model is shown exactly those,
 * with the roster's role mapping applied. That is why a worker that has never
 * seen this conversation before can answer it: the next worker is a stranger,
 * so nothing may depend on live state — the durable record *is* the input.
 *
 * ## Failure is loud, and it is not a retry
 *
 * A run that throws is visible: it lands in `failed` and the run page shows
 * it. A chat turn that throws has a person staring at it, so the same
 * treatment is not enough — this marks the exchange `failed` with a classified
 * reason, which the surface renders as "I couldn't get a turn". It
 * deliberately does **not** re-dispatch: a model that is down will still be
 * down a second later, and a hot retry loop against a single-slot server is
 * how a chat stops every task. The human's next message starts a fresh
 * exchange; that is the retry.
 */
final readonly class ChatEngine
{
    /**
     * The harness's own text for a chat turn.
     *
     * Deliberately short, and deliberately separate from the run preamble:
     * a run is an autonomous executor completing a task with a frozen
     * toolbox, and a conversation is not. What transfers is the posture, not
     * the framing. In particular the "no tools" sentence is load-bearing —
     * this version gives chat no toolbox at all, so a model that assumes it
     * can act is worse than one that says it cannot.
     */
    private const string SYSTEM_PREAMBLE = <<<'TXT'
        You are answering in a conversation. Reply directly to the most recent turn, in your own voice.
        You have no tools in this conversation: if something would require looking it up or taking an
        action, say so plainly rather than inventing a result.
        TXT;

    public function __construct(
        private LlmClientInterface $llm,
        private Roster $roster,
        private Grounding $grounding,
        private ChatRepository $chats,
        private ChatExchangeRepository $exchanges,
        private ChatExchangeEventRepository $events,
        private ClaimStore $claims,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?MessageBusInterface $bus = null,
    ) {
    }

    /**
     * Say something, and start the exchange that will answer it.
     *
     * One transaction, and the same discipline the run engine uses: the turn
     * row and the lane message that will answer it live or die together, so
     * there is no window where a message is committed and no worker can ever
     * be told about it. (The Doctrine transport shares this entity manager's
     * connection, which is what makes the dispatch join the transaction rather
     * than merely follow it.)
     *
     * Attribution is applied here, from the roster, and is never typed by the
     * human: the message arrives as text and leaves as a turn *from Andrew*,
     * with the role the model will see it on stored alongside it.
     */
    public function ask(Chat $chat, string $content, ChatOrigin $origin = ChatOrigin::Web): ChatExchange
    {
        $content = trim($content);
        if ('' === $content) {
            throw new \InvalidArgumentException('A message needs some content.');
        }

        return $this->em->wrapInTransaction(function () use ($chat, $content, $origin): ChatExchange {
            $exchange = new ChatExchange($chat);
            $chat->appendExchange($exchange);
            $this->em->persist($exchange);

            $exchange->appendEvent(ChatExchangeEvent::turn(
                ChatEventType::Message,
                Participant::Andrew,
                $this->roster->roleFor(Participant::Andrew),
                $origin,
                $content,
            ));

            $this->em->flush();

            // Inside the transaction: the reply turn's carrier is committed
            // with the state it will be judged against. Safe to dispatch here
            // because the flush above assigned the ids the message carries.
            $this->enqueue(new ChatReplyMessage((int) $chat->getId(), (int) $exchange->getId()));

            return $exchange;
        });
    }

    /**
     * Open a conversation and say the first thing in it.
     *
     * A conversation needs a title to be findable again, and asking a human to
     * name a conversation before they can speak in it is friction for no gain —
     * so an untitled chat is named from its opening message. This is the one
     * place a title is inferred, and it is deliberately the cheapest possible
     * rule: no model call, no summarizer, just the first few words, which the
     * human can change afterwards.
     */
    public function start(string $content, ChatOrigin $origin = ChatOrigin::Web): Chat
    {
        $chat = new Chat(self::titleFrom($content));
        $this->em->persist($chat);
        $this->em->flush();

        $this->ask($chat, $content, $origin);

        return $chat;
    }

    /**
     * The message that advances this exchange, or null when it needs nothing.
     *
     * Derived from committed state only, exactly as
     * `RunEngine::nextTurnMessage()` is: this is what `app:chat:requeue`
     * dispatches for an exchange whose carrier was lost.
     */
    public function nextTurnMessage(ChatExchange $exchange): ?ChatReplyMessage
    {
        if ($exchange->isTerminal()) {
            return null;
        }

        return new ChatReplyMessage((int) $exchange->getChat()->getId(), (int) $exchange->getId());
    }

    /**
     * Re-dispatch an owed reply, for recovery (`app:chat:requeue`).
     *
     * Routed through the engine's own dispatch path rather than around it, so
     * recovery and the steady state cannot drift apart: same lane, same
     * message, same staleness rules. The extra delivery is a no-op if a worker
     * is already holding the exchange.
     */
    public function requeue(ChatReplyMessage $message): void
    {
        $this->enqueue($message);
    }

    /**
     * One reply turn of an exchange, from a queue delivery.
     *
     * Takes the claim, judges the delivery against committed state *under* the
     * claim, and answers only when this exchange still owes a reply. Anything
     * else — a duplicate, a late redelivery, an exchange already answered — is
     * dropped, doing nothing being the correct processing.
     */
    public function reply(int $exchangeId): ChatExchangeStatus
    {
        $exchange = $this->exchanges->find($exchangeId);
        if (!$exchange instanceof ChatExchange) {
            $this->logger->debug('Dropping ChatReplyMessage: exchange {exchange} no longer exists.', ['exchange' => $exchangeId]);

            return ChatExchangeStatus::Failed;
        }

        // No pre-claim checks: status is judged on state as committed, not on
        // a snapshot that may be stale by the time the claim is won.
        $token = $this->claims->claim(ClaimTarget::ChatExchange, $exchangeId);
        if (null === $token) {
            $this->logLostClaim($exchange, $exchangeId);

            return $exchange->getStatus();
        }

        try {
            if (!$this->refresh($exchange)) {
                return ChatExchangeStatus::Failed;
            }

            if ($exchange->isTerminal()) {
                // A duplicate delivery, or a redelivery after another worker
                // answered. Doing nothing is the correct processing.
                $this->logger->debug('Exchange {exchange}: dropping reply message (status {status} under claim).', [
                    'exchange' => $exchangeId,
                    'status' => $exchange->getStatus()->value,
                ]);

                return $exchange->getStatus();
            }

            return $this->performReply($exchange);
        } finally {
            // The answering path has no successor message, so unlike a run
            // turn there is no successor-delivery window to protect and no
            // need to release inside the commit — the claimant's exit is the
            // claim's exit, exactly as it is for a terminal run. Idempotent,
            // so every exit from here is covered by one statement.
            $this->claims->release(ClaimTarget::ChatExchange, $exchangeId, $token);
        }
    }

    /**
     * The reply itself: compile the conversation, ask the model, record the
     * answer.
     *
     * The exchange leans on the run engine's central property — nothing the
     * model produced is committed until the turn returns — so a worker that
     * dies mid-request leaves the exchange byte-for-byte as owed as it was,
     * with no partial reply to unwind.
     */
    private function performReply(ChatExchange $exchange): ChatExchangeStatus
    {
        $chat = $exchange->getChat();

        if (ChatExchangeStatus::Queued === $exchange->getStatus()) {
            $exchange->markStarted();
        }

        $messages = $this->compileMessages($chat);

        $exchange->appendMachinery(ChatEventType::LlmRequest, [
            'turns' => \count($messages) - 1, // minus the system message
        ]);
        $this->em->flush();

        try {
            $response = $this->llm->chat($messages);
        } catch (LlmRequestException $e) {
            return $this->failExchange($exchange, $e->errorClass, $e->getMessage());
        }

        $content = $response->content;
        if (null === $content || '' === trim($content)) {
            // A run treats a contentless terminal message as malformed rather
            // than a completion (SPEC §5.4); the same reasoning holds here and
            // matters more, because the alternative is a blank bubble the
            // human reads as silence.
            return $this->failExchange(
                $exchange,
                ErrorClass::LlmMalformedResponse,
                'the model returned an empty reply — no content to show',
            );
        }

        $this->em->wrapInTransaction(function () use ($exchange, $chat, $response, $content): void {
            $exchange->appendMachinery(ChatEventType::LlmResponse, [
                'finishReason' => $response->finishReason,
                'usage' => $response->usage,
            ], durationMs: $response->durationMs);

            $exchange->appendEvent(ChatExchangeEvent::turn(
                ChatEventType::Reply,
                Participant::Nia,
                $this->roster->roleFor(Participant::Nia),
                ChatOrigin::Web,
                $content,
            ));

            $exchange->setCheckpoint(['status' => ChatExchangeStatus::Answered->value]);
            $exchange->appendMachinery(ChatEventType::Checkpoint, ['status' => ChatExchangeStatus::Answered->value]);
            $exchange->markAnswered();
            $chat->touch();
            $this->em->flush();
        });

        return ChatExchangeStatus::Answered;
    }

    /**
     * Compile the conversation into the model's message list (SPEC §15, §2.3).
     *
     * Two layers, and the split is the whole point:
     *
     * - **The system message** carries the harness's own voice: the posture,
     *   the roster (which states whose words are whose, so the mapping cannot
     *   be misread from context), and the grounding block.
     * - **The turns** are the transcript, each rendered on the role the roster
     *   assigned it. The human never types a name; attribution is rendered
     *   here, at the render layer, exactly as §2.5 requires.
     *
     * The grounding block is compiled *per request* rather than frozen into
     * the exchange — a deliberate difference from a run. An exchange is
     * short-lived, and a conversation resumed after a day of silence should be
     * grounded in the day it is resumed on, not the day the previous exchange
     * happened.
     *
     * @return list<array{role: string, content: string}>
     */
    private function compileMessages(Chat $chat): array
    {
        $messages = [[
            'role' => 'system',
            'content' => implode("\n\n", [
                self::SYSTEM_PREAMBLE,
                $this->roster->render(),
                "## Grounding\n\n".$this->grounding->render(),
            ]),
        ]];

        foreach ($this->events->findTranscript($chat) as $turn) {
            $messages[] = $turn->toMessage();
        }

        return $messages;
    }

    /**
     * The failure path, shared by every way an exchange can end badly.
     *
     * Note the shape: terminal, classified, and *recorded*, in one commit with
     * a `failure` event the surface reads. Silent failure is the one outcome
     * ruled out — a person is waiting on the other end of this, and the one
     * failure mode with no precedent in the run lanes is exactly the one where
     * nobody is looking.
     */
    private function failExchange(ChatExchange $exchange, ErrorClass $errorClass, string $reason): ChatExchangeStatus
    {
        $this->logger->warning('Exchange {exchange}: failed with {class} — the human is waiting unanswered.', [
            'exchange' => $exchange->getId(),
            'chat' => $exchange->getChat()->getId(),
            'class' => $errorClass->value,
            'reason' => $reason,
        ]);

        $this->em->wrapInTransaction(function () use ($exchange, $errorClass, $reason): void {
            $exchange->appendMachinery(
                ChatEventType::Failure,
                ['reason' => $reason],
                errorClass: $errorClass,
            );
            $exchange->markFailed($errorClass);
            $exchange->getChat()->touch();
            $this->em->flush();
        });

        return ChatExchangeStatus::Failed;
    }

    /**
     * A dropped delivery is worth a warning when the claim that explains it
     * *looks abandoned*: that is the state where nobody is coming and the
     * human stays unanswered, which is the one thing worth waking an operator
     * for. An ordinary duplicate — a live claim, seconds old — is debug noise,
     * because the holder is about to answer.
     */
    private function logLostClaim(ChatExchange $exchange, int $exchangeId): void
    {
        $age = null === $exchange->getClaimedAt() ? null : time() - $exchange->getClaimedAt();

        if (null !== $age && $age >= RunEngine::CLAIM_STALE_SECONDS) {
            $this->logger->warning(
                'Exchange {exchange}: reply message dropped against a claim held {age}s by {fleet} — the holder looks gone and the exchange is unanswered.',
                ['exchange' => $exchangeId, 'age' => $age, 'fleet' => $exchange->getClaimFleet() ?? '(unlabelled)'],
            );

            return;
        }

        $this->logger->debug('Exchange {exchange}: duplicate reply delivery dropped (claim held {age}s).', [
            'exchange' => $exchangeId,
            'age' => $age,
        ]);
    }

    /**
     * Re-read the exchange under the claim: the answer / drop decision must be
     * made on state as committed, not on what was loaded before the claim was
     * won.
     */
    private function refresh(ChatExchange $exchange): bool
    {
        try {
            $this->em->refresh($exchange);

            return true;
        } catch (EntityNotFoundException) {
            return false;
        }
    }

    private function enqueue(object $message): void
    {
        $bus = $this->bus ?? throw new \LogicException('ChatEngine has no message bus configured — reply dispatch requires it.');

        $bus->dispatch($message);
    }

    /**
     * The conversations this engine can reach — a thin pass-through so a
     * controller depends on one service rather than three repositories.
     *
     * @return list<Chat>
     */
    public function conversations(): array
    {
        return $this->chats->findRecent();
    }

    /** A conversation's opening words become its name if nobody supplied one. */
    private static function titleFrom(string $content): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $content) ?? $content);

        return '' === $flat ? 'New conversation' : mb_substr($flat, 0, 60);
    }
}
