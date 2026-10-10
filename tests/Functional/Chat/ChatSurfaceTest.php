<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchange;
use App\Entity\ChatExchangeEvent;
use App\Entity\ErrorClass;
use App\Entity\Participant;
use App\Entity\TurnRole;
use App\Llm\LlmClientInterface;
use App\Security\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The chat surface over real HTTP (SPEC §15, §8).
 *
 * The auth boundary, the post path, and — the one that matters most — that a
 * failure is *said out loud* rather than leaving a person watching a blank
 * space where a reply should be.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatSurfaceTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\ChatExchangeEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\ChatExchange')->execute();
        $em->createQuery('DELETE FROM App\Entity\Chat')->execute();
        $em->flush();

        $this->llm = $this->createMock(LlmClientInterface::class);
        static::getContainer()->set(LlmClientInterface::class, $this->llm);
    }

    public function testTheChatSurfaceSitsBehindTheAdminSession(): void
    {
        static::ensureKernelShutdown();
        $anonymous = static::createClient();

        $anonymous->request('GET', '/chat');

        self::assertTrue($anonymous->getResponse()->isRedirect('/login'));
    }

    public function testAnEmptyConversationListSaysSoRatherThanRenderingNothing(): void
    {
        $crawler = $this->client->request('GET', '/chat');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing said yet.', $crawler->filter('body')->text());
    }

    public function testStartingAConversationRedirectsToItAndShowsTheFirstTurn(): void
    {
        $crawler = $this->client->request('GET', '/chat');
        $token = $crawler->filter('form[action$="/chat/new"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/chat/new', ['_token' => $token, 'message' => 'morning']);

        $chat = $this->onlyChat();
        self::assertTrue($this->client->getResponse()->isRedirect('/chat/'.$chat->getId()));

        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('morning', $page->filter('.chat-transcript')->text());
    }

    /**
     * The transcript renders both speakers, labelled. The label is the
     * *reader's* rendering of a stored turn: the model sees roles and never a
     * visible name prefix (SPEC §15 §2.5), which is a different rendering of
     * the same row.
     */
    public function testTheTranscriptShowsBothSpeakersLabelled(): void
    {
        $chat = $this->seedConversation('What is the weather?', 'Clear, 56°F.');

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();

        $speakers = $crawler->filter('.chat-speaker')->each(static fn ($node): string => trim($node->text()));

        self::assertCount(2, $speakers);
        self::assertStringContainsString('You', $speakers[0]);
        self::assertStringContainsString('Nia', $speakers[1]);

        self::assertStringContainsString('Clear, 56°F.', $crawler->filter('.chat-transcript')->text());
    }

    public function testMessagesRequireAValidCsrfToken(): void
    {
        $chat = $this->seedConversation('hello', 'hi');

        $this->client->request('POST', '/chat/'.$chat->getId().'/say', ['_token' => 'not-the-token', 'message' => 'sneaky']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEmptyMessageIsRefusedRatherThanStored(): void
    {
        $chat = $this->seedConversation('hello', 'hi');
        $token = $this->sayToken($chat);

        $this->client->request('POST', '/chat/'.$chat->getId().'/say', ['_token' => $token, 'message' => '   ']);

        self::assertTrue($this->client->getResponse()->isRedirect('/chat/'.$chat->getId()));

        $this->client->followRedirect();
        self::assertStringContainsString('not a turn', $this->client->getResponse()->getContent() ?: '');

        $em = $this->em();
        $em->clear();
        self::assertCount(1, $em->getRepository(ChatExchange::class)->findAll(), 'no exchange was created for an empty message');
    }

    public function testAskingAddsATurnAndQueuesTheReply(): void
    {
        $chat = $this->seedConversation('hello', 'hi');
        $token = $this->sayToken($chat);

        $this->client->request('POST', '/chat/'.$chat->getId().'/say', ['_token' => $token, 'message' => 'and again']);

        self::assertTrue($this->client->getResponse()->isRedirect('/chat/'.$chat->getId()));

        $page = $this->client->followRedirect();
        self::assertStringContainsString('and again', $page->filter('.chat-transcript')->text());

        // The new exchange is not answered yet, so the page says so rather than
        // looking like the assistant ignored it.
        self::assertStringContainsString('thinking', $page->filter('body')->text());
    }

    /**
     * THE FAILURE, AT THE SURFACE.
     *
     * A run that fails is visible in the run history; a chat turn that fails
     * has somebody staring at it. So the page must carry the classified reason
     * — this is the one requirement in the design with no precedent in the run
     * lanes (SPEC §15).
     */
    public function testAFailedExchangeIsShownToTheHumanRatherThanLeftSilent(): void
    {
        $chat = $this->seedConversation('hello', 'hi');
        $this->failLatestExchange($chat);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();

        $alert = $crawler->filter('[role="alert"]');
        self::assertCount(1, $alert, 'a failed turn must announce itself');
        self::assertStringContainsString("couldn't get a turn", $alert->text());
        self::assertStringContainsString('llm_error', $alert->text(), 'the classified reason is shown, not guessed at');
    }

    public function testAnAnsweredConversationDoesNotAnnounceAFailure(): void
    {
        $chat = $this->seedConversation('hello', 'hi');

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertCount(0, $crawler->filter('[role="alert"]'));
        self::assertStringNotContainsString('thinking', $crawler->filter('body')->text());
    }

    public function testAnUnknownConversationIsA404(): void
    {
        $this->client->request('GET', '/chat/99999');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * THE WINDOW'S CONFESSION (SPEC §15.9).
     *
     * Once the model's window has shed part of the conversation, the page
     * says so in plain words — because "she can no longer see the start of
     * this conversation" changes what it makes sense to ask for, and it is
     * worse to leave that unsaid than any ledger row.
     */
    public function testThePageSaysWhenTheModelCanNoLongerSeeTheStartOfTheConversation(): void
    {
        $chat = $this->seedConversation('hello', 'hi');
        $this->recordATrim($chat, droppedTurns: 7, droppedRounds: 2);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();

        $notice = $crawler->filter('[role="status"]')->text();
        self::assertStringContainsString('no longer see', $notice);
        self::assertStringContainsString('7 turns and 2 tool rounds', $notice, 'the counts are stated, not hand-waved');
    }

    /**
     * And a conversation inside the window is not announced as trimmed: the
     * ordinary case must not read like a problem.
     */
    public function testAnUntrimmedConversationCarriesNoWindowNotice(): void
    {
        $chat = $this->seedConversation('hello', 'hi');

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[role="status"]'), 'nothing to confess');
    }

    /** Record the one ledger row a trim leaves, as the engine writes it. */
    private function recordATrim(Chat $chat, int $droppedTurns, int $droppedRounds): void
    {
        $em = $this->em();
        $exchange = $em->getRepository(ChatExchange::class)->findOneBy(['chat' => $chat]);

        self::assertInstanceOf(ChatExchange::class, $exchange);

        $exchange->appendMachinery(ChatEventType::ContextTrim, [
            'keptTurns' => 3,
            'keptRounds' => 1,
            'droppedTurns' => $droppedTurns,
            'droppedRounds' => $droppedRounds,
            'estimatedTokens' => 4000,
            'limitTokens' => 32768,
        ]);

        $em->flush();
    }

    private function sayToken(Chat $chat): string
    {
        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $token = $crawler->filter('form[action$="/say"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    /**
     * A conversation with one answered exchange, built directly so the surface
     * tests do not depend on the worker path they are not testing.
     */
    private function seedConversation(string $said, string $replied): Chat
    {
        $em = $this->em();

        $chat = new Chat($said);
        $em->persist($chat);

        $exchange = new ChatExchange($chat);
        $chat->appendExchange($exchange);
        $em->persist($exchange);

        $exchange->appendEvent(ChatExchangeEvent::turn(
            ChatEventType::Message,
            Participant::Andrew,
            TurnRole::User,
            \App\Entity\ChatOrigin::Web,
            $said,
        ));
        $exchange->appendEvent(ChatExchangeEvent::turn(
            ChatEventType::Reply,
            Participant::Nia,
            TurnRole::Assistant,
            \App\Entity\ChatOrigin::Web,
            $replied,
        ));
        $exchange->markStarted();
        $exchange->markAnswered();

        $em->flush();

        return $chat;
    }

    /** Turn an exchange into the state a failed reply leaves behind. */
    private function failLatestExchange(Chat $chat): void
    {
        $em = $this->em();
        $exchange = $em->getRepository(ChatExchange::class)->findOneBy(['chat' => $chat]);

        self::assertInstanceOf(ChatExchange::class, $exchange);

        $exchange->appendMachinery(
            ChatEventType::Failure,
            ['reason' => 'LLM transport failure: endpoint unreachable'],
            errorClass: ErrorClass::LlmError,
        );
        $exchange->markFailed(ErrorClass::LlmError);

        $em->flush();
    }

    private function onlyChat(): Chat
    {
        $chats = $this->em()->getRepository(Chat::class)->findAll();
        self::assertCount(1, $chats);

        return $chats[0];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
