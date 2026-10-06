<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Chat\ChatEngine;
use App\Command\ChatRequeueCommand;
use App\Command\RunRequeueCommand;
use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Entity\Participant;
use App\Entity\TurnRole;
use App\RunEngine\ClaimReaper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Boot recovery for chat exchanges (SPEC §6.2, §15).
 *
 * The scenario is the one the graceful-restart work is about, one aggregate
 * over: a fleet killed rather than stopped leaves an exchange claimed by a
 * process that will never release it, with its carrier message gone with the
 * process. A run in that state is work that stopped. An *exchange* in that
 * state is a person sitting in front of a conversation that will never
 * continue — which is why the sweep has to cover it, and why a third
 * claimable aggregate must not be able to be added and quietly left out.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatBootRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)

    private string|false $restoreGrabAfter = false;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchangeEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchange')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Chat')->execute();
        $this->em->flush();

        $this->restoreGrabAfter = getenv(ClaimReaper::GRAB_AFTER_ENV);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (false === $this->restoreGrabAfter) {
            putenv(ClaimReaper::GRAB_AFTER_ENV);
        } else {
            putenv(ClaimReaper::GRAB_AFTER_ENV.'='.$this->restoreGrabAfter);
        }

        parent::tearDown();
    }

    /**
     * The sweep clears a chat exchange's abandoned claim, not just a run's.
     *
     * This is the regression test for "the sweep covers every claimable
     * aggregate": the reaper is written against the shared ClaimStore, and the
     * failure it prevents is a second aggregate added later that boot recovery
     * silently does not know about.
     */
    public function testTheClaimReaperClearsAbandonedChatExchangeClaims(): void
    {
        $exchange = $this->seedClaimedExchange();

        $reaped = static::getContainer()->get(ClaimReaper::class)->reap();

        self::assertSame(0, $reaped['runs']);
        self::assertSame(1, $reaped['chat_exchanges'], 'an abandoned exchange claim is exactly as stuck as an abandoned run claim');

        $this->em->refresh($exchange);
        self::assertNull($exchange->getClaimedAt());
        self::assertNull($exchange->getClaimFleet());
        self::assertSame(ChatExchangeStatus::Running, $exchange->getStatus(), 'the sweep clears the claim and nothing else — committed state is where work resumes');
    }

    /**
     * The grace bound applies to chat exchanges too, so the multi-fleet
     * topology is not protected on one table and unprotected on the other.
     */
    public function testAFreshChatClaimSurvivesWhenTheGraceBoundIsRaised(): void
    {
        putenv(ClaimReaper::GRAB_AFTER_ENV.'=3600');
        $exchange = $this->seedClaimedExchange();

        $reaped = static::getContainer()->get(ClaimReaper::class)->reap();

        self::assertSame(0, $reaped['chat_exchanges'], 'a claim fresher than the bound may belong to a live peer');

        $this->em->refresh($exchange);
        self::assertNotNull($exchange->getClaimedAt());
    }

    /**
     * And the boot sweep's own report names the split, because the two
     * aggregates have different consequences and an operator scanning the boot
     * block should not have to count to know which they are looking at.
     */
    public function testTheBootSweepReportsChatExchangesSeparately(): void
    {
        $this->seedClaimedExchange();
        $this->asFleetOwner();

        $tester = $this->runConsole(RunRequeueCommand::class, ['--startup' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Claims: cleared 1 (any age)', $tester->getDisplay());
        self::assertStringContainsString('of which chat exchanges: cleared 1', $tester->getDisplay());
    }

    /**
     * The requeue half: an exchange with committed state and no carrier is
     * re-derived from the row and dispatched. `seq` never enters into it — the
     * row is the durable queue of record, exactly as it is for a run.
     */
    public function testTheChatRequeueDispatchesTheReplyAnExchangeIsOwed(): void
    {
        $exchange = $this->seedClaimedExchange();
        $this->em->getConnection()->executeStatement('UPDATE chat_exchange SET claimed_at = NULL, claim_fleet = NULL WHERE id = :id', ['id' => $exchange->getId()]);

        $tester = $this->runConsole(ChatRequeueCommand::class);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 exchange(s) requeued, 0 skipped.', $tester->getDisplay());

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(\App\Message\ChatReplyMessage::class, $sent[0]->getMessage());
    }

    /**
     * An answered conversation is not even considered.
     *
     * The sweep walks the *active* exchanges, so a settled one never enters
     * the loop — hence "0 skipped" rather than "1 skipped". Worth pinning
     * because the tempting alternative (walk every exchange and filter inside)
     * turns this command into a full table scan of every conversation ever
     * held, on the boot path.
     */
    public function testAnAnsweredExchangeIsNotWalkedAtAll(): void
    {
        $chat = $this->newChat();
        $exchange = new ChatExchange($chat);
        $chat->appendExchange($exchange);
        $this->em->persist($exchange);

        $exchange->appendEvent(ChatExchangeEvent::turn(ChatEventType::Message, Participant::Andrew, TurnRole::User, ChatOrigin::Web, 'hello'));
        $exchange->markAnswered();
        $this->em->flush();

        $tester = $this->runConsole(ChatRequeueCommand::class);

        self::assertStringContainsString('0 exchange(s) requeued, 0 skipped.', $tester->getDisplay());
        self::assertCount(0, $this->sent());
    }

    public function testTheDryRunReportsWithoutDispatching(): void
    {
        $this->seedClaimedExchange();

        $tester = $this->runConsole(ChatRequeueCommand::class, ['--dry-run' => true]);

        self::assertStringContainsString('1 exchange(s) would be requeued', $tester->getDisplay());
        self::assertCount(0, $this->sent());
    }

    /**
     * The engine derives what an exchange owes from committed state alone,
     * which is the property everything above rests on.
     */
    public function testTheOwedReplyIsDerivedFromCommittedState(): void
    {
        $exchange = $this->seedClaimedExchange();
        $engine = static::getContainer()->get(ChatEngine::class);

        $message = $engine->nextTurnMessage($exchange);
        self::assertNotNull($message);
        self::assertSame((int) $exchange->getId(), $message->exchangeId);

        $this->em->refresh($exchange);
        $exchange->markAnswered();

        self::assertNull($engine->nextTurnMessage($exchange));
    }

    private function seedClaimedExchange(): ChatExchange
    {
        $chat = $this->newChat();

        $exchange = new ChatExchange($chat);
        $chat->appendExchange($exchange);
        $this->em->persist($exchange);

        $exchange->appendEvent(ChatExchangeEvent::turn(ChatEventType::Message, Participant::Andrew, TurnRole::User, ChatOrigin::Web, 'are you there?'));
        $exchange->markStarted();
        $this->em->flush();

        // Stamp the claim the way the engine does: raw SQL, so the entity's own
        // flush can never be what writes it.
        $this->em->getConnection()->executeStatement(
            'UPDATE chat_exchange SET lock_version = lock_version + 1, claimed_at = :now, claim_fleet = :fleet WHERE id = :id',
            ['now' => time(), 'fleet' => 'the-dead-predecessor', 'id' => $exchange->getId()],
        );

        $this->em->refresh($exchange);

        return $exchange;
    }

    private function newChat(): Chat
    {
        $chat = new Chat('Are you there?');
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function asFleetOwner(): void
    {
        putenv('TASKLOOM_FLEET_OWNER=1');
    }

    /**
     * Named `runConsole` rather than `runCommand`: KernelTestCase already has
     * a static `runCommand()`, and redeclaring it non-static is a fatal error.
     *
     * @param class-string         $command
     * @param array<string, mixed> $args
     */
    private function runConsole(string $command, array $args = []): CommandTester
    {
        $tester = new CommandTester(static::getContainer()->get($command));
        $tester->execute($args);

        return $tester;
    }

    /** @return list<\Symfony\Component\Messenger\Envelope> */
    private function sent(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.chat');

        return $transport->getSent();
    }
}
