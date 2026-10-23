<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Admin\TaskAdminService;
use App\Chat\ChatBindingException;
use App\Chat\ChatEngine;
use App\Chat\ChatSessionBinder;
use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatOrigin;
use App\Entity\Participant;
use App\Entity\Run;
use App\Entity\SessionMemory;
use App\Entity\SessionMemoryKind;
use App\Entity\SessionMemorySource;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Entity\TurnRole;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One context, reached two ways: a chat bound to a session answers from that
 * session's objective and notes (docs/design/SESSION_TASKS.md §8.2; SPEC §15).
 *
 * ## What these tests are actually defending
 *
 * Not "the column round-trips". The claim worth a test is that the **chat prompt
 * carries the session's memory block**, because that is the whole feature — and
 * it is a claim that can fail silently. A binding that saves correctly and
 * renders nothing still produces a plausible reply, from a stranger, under her
 * name. So the assertions here read the messages the model was actually handed.
 *
 * The other silent failure is the ordinary chat: adding a session header to
 * every conversation would cost the prefix cache of every chat that has no
 * session at all (KV_CACHE_POSITION_FINDINGS.md), which nobody would notice
 * until the bill arrived. That case asserts the exact absence.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatSessionBinderTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ChatEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ChatSessionBinder $binder; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchangeEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchange')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Chat')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemoryRevision')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemory')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();

        $this->llm = $this->createMock(LlmClientInterface::class);

        $container = static::getContainer();
        $container->set(LlmClientInterface::class, $this->llm);
        $this->engine = $container->get(ChatEngine::class);
        $this->binder = $container->get(ChatSessionBinder::class);
    }

    /**
     * The feature, stated once: her objective reaches the chat prompt.
     */
    public function testABoundChatCarriesTheSessionsObjective(): void
    {
        $session = $this->makeSession('Inventory reconciliation');
        $this->remember($session, SessionMemoryKind::Objective, 'Reconcile the warehouse count against the ledger by Friday.');

        $chat = $this->bindChat($session);
        $messages = $this->askAndCapture($chat);

        $prompt = $this->systemPrompt($messages);

        self::assertStringContainsString('## Memories', $prompt, 'the block is rendered by the session\'s own renderer');
        self::assertStringContainsString('Reconcile the warehouse count', $prompt, 'and it carries what she actually knows');
        self::assertStringContainsString('Inventory reconciliation', $prompt, 'the human can tell which session they are talking to');
    }

    /**
     * Notes reach it too, not just the objective — a session with notes and no
     * objective is an ordinary state, and one this could plausibly have missed.
     */
    public function testABoundChatCarriesHerNotes(): void
    {
        $session = $this->makeSession('Notes only');
        $this->remember($session, SessionMemoryKind::Note, 'The vendor replied: they will re-ship on Monday.');

        $prompt = $this->systemPrompt($this->askAndCapture($this->bindChat($session)));

        self::assertStringContainsString('## Memories', $prompt);
        self::assertStringContainsString('they will re-ship on Monday', $prompt);
    }

    /**
     * The regression that costs money rather than correctness: an ordinary chat
     * must compile exactly what it compiled before sessions existed.
     */
    public function testAnUnboundChatGetsNoSessionBlock(): void
    {
        $session = $this->makeSession('Unrelated work');
        $this->remember($session, SessionMemoryKind::Objective, 'A fact that must not leak into an ordinary chat.');

        $chat = new Chat('Ordinary conversation');
        $this->em->persist($chat);
        $this->em->flush();

        $prompt = $this->systemPrompt($this->askAndCapture($chat));

        self::assertStringNotContainsString('## Session', $prompt);
        self::assertStringNotContainsString('## Memories', $prompt);
        self::assertStringNotContainsString('must not leak', $prompt);
    }

    /**
     * A binding survives an approved edit, because the session outlives its task
     * rows (§8.2, §4.4): bind to the original, approve a replacement, and the
     * chat still resolves — to the successor, which is the same session.
     *
     * This is the case a naive `chat.session_id` would get wrong, and get wrong
     * silently: the pointer would still be non-null, the page would still say
     * "about a session", and the memory block would quietly vanish.
     */
    public function testABindingFollowsTheChainToTheLiveEnd(): void
    {
        $original = $this->makeSession('Long-running session');
        $chat = $this->bindChat($original);

        $successor = $original->createReplacementDraft(TaskAuthor::User);
        $successor->setTitle('Long-running session (edited)');
        $this->em->persist($successor);
        $this->em->flush();

        // Approval is the swap: the draft becomes enabled, the original is
        // archived with superseded_by pointing at it (SPEC §4.4).
        $approved = $this->approve((int) $successor->getId());

        $resolved = $this->binder->forChat($chat);

        self::assertNotNull($resolved, 'the session survives the swap');
        self::assertSame($approved->getId(), $resolved->getId(), 'and resolves to the version that is live now');
        self::assertSame('Long-running session (edited)', $resolved->getTitle());
    }

    /**
     * …and the memory still reaches the prompt through the *new* row, because
     * the store is reparented on approval rather than orphaned.
     */
    public function testTheMemoryStillReachesThePromptAfterTheSwap(): void
    {
        $original = $this->makeSession('Edited session');
        $this->remember($original, SessionMemoryKind::Objective, 'Keep the objective across the edit.');
        $chat = $this->bindChat($original);

        $successor = $original->createReplacementDraft(TaskAuthor::User);
        $this->em->persist($successor);
        $this->em->flush();

        $this->approve((int) $successor->getId());

        $prompt = $this->systemPrompt($this->askAndCapture($chat));

        self::assertStringContainsString('Keep the objective across the edit', $prompt);
    }

    /**
     * A run task is refused, by name, rather than silently producing a chat that
     * renders nothing.
     */
    public function testBindingARunTaskIsRefused(): void
    {
        $run = new Task('A plain run', 'Do a thing.', TaskKind::Run, ToolboxMode::Tags, [], TaskAuthor::User);
        $this->em->persist($run);
        $this->em->flush();

        $chat = new Chat('Ordinary conversation');

        $this->expectException(ChatBindingException::class);
        $this->expectExceptionMessage('only a session');

        $this->binder->bind($chat, $run);
    }

    /**
     * Unbinding is a supported end state, not merely "never bound".
     */
    public function testUnbindingReturnsTheChatToOrdinary(): void
    {
        $chat = $this->bindChat($this->makeSession('Something'));

        self::assertNotNull($this->binder->forChat($chat));

        $this->binder->bind($chat, null);

        self::assertNull($this->binder->forChat($chat));
    }

    /**
     * A chat whose session has never run says so by omission rather than by
     * claiming a clean record.
     */
    public function testTheSliceLineAppearsOnlyOnceSheHasRun(): void
    {
        $session = $this->makeSession('Never run');
        $this->remember($session, SessionMemoryKind::Objective, 'Something to do.');

        $before = $this->systemPrompt($this->askAndCapture($this->bindChat($session)));
        self::assertStringNotContainsString('most recent slice', $before);

        $run = new Run($session);
        $run->markStarted();
        $run->markSucceeded();
        $this->em->persist($run);
        $this->em->flush();

        $after = $this->systemPrompt($this->askAndCapture($this->bindChat($session)));
        self::assertStringContainsString('most recent slice', $after);
    }

    /**
     * Approve through the service, not the entity: the entity's approve() swaps
     * the chain, and the service is what also carries the memory across
     * (TaskAdminService::approveTask()). Driving the entity directly is how an
     * approval loses a session's history while looking like it worked.
     */
    private function approve(int $taskId): Task
    {
        return static::getContainer()->get(TaskAdminService::class)->approveTask($taskId);
    }

    /**
     * A deleted task must not take the conversation with it.
     *
     * Nothing deletes tasks today (`TaskRepository::remove()` has no caller), so
     * this is a constraint test rather than a behaviour test — which is exactly
     * why it is worth having before the first caller: `ON DELETE SET NULL` versus
     * `CASCADE` is one word in a migration and the difference between "this chat
     * is no longer about anything" and "this human's messages are gone". It was
     * `CASCADE` until this test existed.
     *
     * Driven through raw SQL because the point is the database's own rule, not
     * Doctrine's opinion of the object graph: a Doctrine-level `remove()` would
     * exercise the ORM's cascade logic and could pass while the FK says otherwise.
     */
    public function testDeletingTheSessionLeavesTheConversationAndItsTranscript(): void
    {
        $session = $this->makeSession('A session someone will delete');
        $chat = $this->bindChat($session);

        $said = 'a question the human typed';
        $exchange = new ChatExchange($chat);
        $chat->appendExchange($exchange);
        $exchange->appendEvent(ChatExchangeEvent::turn(
            ChatEventType::Message,
            Participant::Andrew,
            TurnRole::User,
            ChatOrigin::Web,
            $said,
        ));
        $this->em->persist($exchange);
        $this->em->flush();

        $chatId = (int) $chat->getId();
        $exchangeId = (int) $exchange->getId();
        $sessionId = (int) $session->getId();

        $connection = $this->em->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('DELETE FROM task WHERE id = :id', ['id' => $sessionId]);
        $this->em->clear();

        $surviving = $this->em->getRepository(Chat::class)->find($chatId);

        self::assertNotNull($surviving, 'the conversation outlives its subject');
        self::assertNull($surviving->getSession(), 'and it is simply no longer about a session');
        self::assertSame(
            (int) $exchangeId,
            (int) $connection->fetchOne('SELECT id FROM chat_exchange WHERE chat_id = :id', ['id' => $chatId]),
            'the exchange survives, because the task row had nothing to do with it',
        );
        self::assertSame(
            $said,
            $connection->fetchOne('SELECT content FROM chat_exchange_event WHERE exchange_id = :id', ['id' => $exchangeId]),
            'and so does the human\u{2019}s own words',
        );
    }

    /**
     * The status line is chain-scoped too, and that is not a detail.
     *
     * `findNewestForTask()` asks the row, so after an approved edit the successor
     * has no runs of its own and the line simply disappears — the chat partner
     * then says nothing about her work while the session page shows it finished.
     * That is the same failure the continuity fix exists to prevent, one level up:
     * state was made chain-scoped (§8.2) and this reads state.
     *
     * Worth its own test because it fails *quietly by omission*: an absent
     * sentence looks like a session that has not run yet, not like a bug. The
     * first version of this code had this bug, with a comment above it claiming
     * the opposite.
     */
    public function testTheStatusLineSurvivesAnApprovedEdit(): void
    {
        $original = $this->makeSession('A session that will run and then be edited');
        $chat = $this->bindChat($original);

        $run = new Run($original);
        $run->markStarted();
        $run->markSucceeded();
        $this->em->persist($run);
        $this->em->flush();

        self::assertStringContainsString(
            'Its most recent slice succeeded',
            $this->systemPrompt($this->askAndCapture($chat)),
            'before the edit: the run happened and the chat knows it',
        );

        $successor = $original->createReplacementDraft(TaskAuthor::User);
        $this->em->persist($successor);
        $this->em->flush();
        $this->approve((int) $successor->getId());

        self::assertStringContainsString(
            'Its most recent slice succeeded',
            $this->systemPrompt($this->askAndCapture($chat)),
            'after the edit: the ledger is still on the old row, and the session is still the one that ran',
        );
    }

    private function bindChat(?Task $session): Chat
    {
        $chat = new Chat('A conversation');
        $this->binder->bind($chat, $session);
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function makeSession(string $title): Task
    {
        $task = new Task($title, 'A session for the binder tests.', TaskKind::Session, ToolboxMode::Tags, [], TaskAuthor::User);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function remember(Task $session, SessionMemoryKind $kind, string $text): void
    {
        $this->em->persist(new SessionMemory($session, $kind, $text, SessionMemorySource::Operator));
        $this->em->flush();
    }

    /**
     * Ask in `$chat`, let the reply land, and return the messages the model was
     * handed. The prompt is the artifact under test; everything else is the
     * scaffolding that gets us to it.
     *
     * @return list<array<string, mixed>>
     */
    private function askAndCapture(Chat $chat): array
    {
        $captured = [];

        $this->llm->method('chat')->willReturnCallback(
            static function (array $messages) use (&$captured): LlmResponse {
                $captured = $messages;

                return new LlmResponse('A reply.', 'stop', [], ['total_tokens' => 12], null, 42);
            },
        );

        $exchange = $this->engine->ask($chat, 'What are you working on?');
        $this->engine->reply((int) $exchange->getId());

        self::assertNotSame([], $captured, 'the model was actually called');

        return $captured;
    }

    /** @param list<array<string, mixed>> $messages */
    private function systemPrompt(array $messages): string
    {
        foreach ($messages as $message) {
            if ('system' === ($message['role'] ?? null)) {
                return (string) ($message['content'] ?? '');
            }
        }

        self::fail('No system message was handed to the model.');
    }
}
