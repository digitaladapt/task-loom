<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Entity\Chat;
use App\Entity\ChatExchange;
use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Security\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Choosing a conversation's tools at the surface (SPEC §15.8).
 *
 * The picker is the same widget the task editor uses, so what these tests are
 * really pinning is the *contract between the form and the freeze*: a checked
 * box becomes a frozen snapshot on the exchange that the message started, and
 * nothing about a later message can change it.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatToolSurfaceTest extends WebTestCase
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
        foreach (['ChatExchangeEvent', 'ChatExchange', 'Chat', 'Tool', 'McpServer'] as $entity) {
            $em->createQuery('DELETE FROM App\\Entity\\'.$entity)->execute();
        }
        $em->flush();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->llm->method('chat')->willReturn(new LlmResponse('ok', 'stop', [], [], null, 5));
        static::getContainer()->set(LlmClientInterface::class, $this->llm);
    }

    /**
     * The picker is on the page, offering the catalog's tags and tools.
     */
    public function testTheSurfaceOffersTheToolboxPicker(): void
    {
        $this->seedTool('get_weather', ['weather']);

        $crawler = $this->client->request('GET', '/chat');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('fieldset.toolbox'), 'the picker renders');
        self::assertStringContainsString('toolbox_mode', $this->client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('weather', $crawler->filter('fieldset.toolbox')->text());
        self::assertStringContainsString('get_weather', $crawler->filter('fieldset.toolbox')->text());
    }

    /**
     * A TAG selection freezes the tools the tag resolves to, on the exchange
     * the first message started.
     */
    public function testATagSelectionIsResolvedAndFrozenOnTheExchange(): void
    {
        $this->seedTool('get_weather', ['weather']);

        $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);

        $exchange = $this->onlyExchange();
        self::assertNotNull($exchange->getToolboxSnapshot(), 'the toolbox froze at exchange start');
        self::assertSame('get_weather', $exchange->getToolboxSnapshot()[0]['tool'] ?? null);
        self::assertSame(['mode' => 'tags', 'declared' => ['weather']], $exchange->getToolboxDeclaration());
    }

    /**
     * An EXPLICIT selection names the tool, and resolves to exactly it.
     */
    public function testAnExplicitSelectionIsResolvedAndFrozen(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $this->seedTool('send_email', ['email']);

        $this->startChatWithTools(['toolbox_mode' => 'explicit', 'toolbox_tools' => ['send_email']]);

        $exchange = $this->onlyExchange();
        self::assertCount(1, $exchange->getToolboxSnapshot() ?? []);
        self::assertSame('send_email', $exchange->getToolboxSnapshot()[0]['tool'] ?? null);
    }

    /**
     * Only the SELECTED mode's list is honoured — the property the shared
     * parser exists to keep. The inactive panel is still submitted by the
     * browser (it is hidden, not removed), so a stale checkbox must not leak.
     */
    public function testTheHiddenPickerDoesNotLeakIntoTheFrozenToolbox(): void
    {
        $this->seedTool('get_weather', ['weather']);

        // Both panels submitted; the mode says explicit, and the explicit list
        // is the only one that may count.
        $this->startChatWithTools([
            'toolbox_mode' => 'explicit',
            'toolbox_tags' => ['weather'],
            'toolbox_tools' => ['nothing_by_this_name'],
        ]);

        $exchange = $this->onlyExchange();
        self::assertSame(
            ['mode' => 'explicit', 'declared' => ['nothing_by_this_name']],
            $exchange->getToolboxDeclaration(),
            'only the selected mode\'s list is read',
        );

        // A name the catalog does not carry resolves to nothing — and both
        // halves of that are recorded. The declaration survives so the picker
        // reopens showing what was typed; the empty snapshot is what the model
        // sees.
        self::assertSame([], $exchange->getToolboxSnapshot());

        // The flash is on the redirect's *target*, so it has to be followed
        // before it can be read.
        $this->client->followRedirect();
        self::assertStringContainsString('Nothing in the catalog matches', $this->client->getResponse()->getContent() ?: '');
    }

    /**
     * Choosing nothing is the ordinary case and freezes nothing — leaving the
     * columns NULL, exactly as a pre-tools exchange's are.
     */
    public function testChoosingNoToolsFreezesNothing(): void
    {
        $this->seedTool('get_weather', ['weather']);

        $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => []]);

        $exchange = $this->onlyExchange();
        self::assertNull($exchange->getToolboxSnapshot());
        self::assertNull($exchange->getToolboxDeclaration());
    }

    /**
     * While a reply is pending the picker and the box are DISABLED — the
     * toolbox froze when that exchange started, so a control that accepted a
     * change would be a control that silently did nothing.
     */
    public function testThePickerAndBoxAreInertWhileAReplyIsPending(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('textarea[name="message"][disabled]')->count());
        self::assertGreaterThan(0, $crawler->filter('fieldset.toolbox input[disabled]')->count());
    }

    /**
     * And a hand-rolled POST that ignores the disabled control is REFUSED
     * rather than accepted-and-ignored.
     */
    public function testAPostThatChangesToolsMidReplyIsRefused(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $token = $crawler->filter('form[action$="/say"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/chat/'.$chat->getId().'/say', [
            '_token' => $token,
            'message' => 'and now with different tools',
            'toolbox_mode' => 'tags',
            'toolbox_tags' => ['weather'],
        ]);

        $this->client->followRedirect();
        self::assertStringContainsString('the toolbox is fixed', $this->client->getResponse()->getContent() ?: '');

        $em = $this->em();
        $em->clear();
        self::assertCount(1, $em->getRepository(ChatExchange::class)->findAll(), 'no second exchange was created');
    }

    /**
     * AN UNPARSEABLE MODE IS REFUSED, not guessed at. The toolbox is a
     * permission decision, so a submission nobody can parse must not resolve
     * to something the sender did not ask for.
     */
    public function testAnUnknownToolboxModeIsRefused(): void
    {
        $this->seedTool('get_weather', ['weather']);

        $crawler = $this->client->request('GET', '/chat');
        $token = $crawler->filter('form[action$="/chat/new"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/chat/new', [
            '_token' => $token,
            'message' => 'hello',
            'toolbox_mode' => 'everything', // not a mode the picker can produce
            'toolbox_tools' => ['get_weather'],
        ]);

        $this->client->followRedirect();
        self::assertStringContainsString('Unknown toolbox mode', $this->client->getResponse()->getContent() ?: '');

        $em = $this->em();
        $em->clear();
        self::assertCount(0, $em->getRepository(Chat::class)->findAll(), 'nothing was created');
    }

    /**
     * The used tools are visible in the transcript — the reader's view of what
     * she did, distinct from what she said.
     */
    public function testAToolCallShowsInTheTranscript(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);

        $em = $this->em();
        $exchange = $em->getRepository(ChatExchange::class)->findOneBy(['chat' => $chat]);
        self::assertInstanceOf(ChatExchange::class, $exchange);
        $exchange->appendMachinery(\App\Entity\ChatEventType::ToolCall, [
            'calls' => [['id' => 'c1', 'name' => 'get_weather', 'arguments' => []]],
            'assistantContent' => null,
        ]);
        $exchange->markAnswered();
        $em->flush();

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.chat-tool'));
        self::assertStringContainsString('get_weather', $crawler->filter('.chat-tool')->text());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $toolbox
     */
    private function startChatWithTools(array $toolbox): Chat
    {
        $crawler = $this->client->request('GET', '/chat');
        $token = $crawler->filter('form[action$="/chat/new"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/chat/new', $toolbox + [
            '_token' => $token,
            'message' => 'morning — what is on my plate today?',
        ]);

        $redirect = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($redirect, 'the form redirects to the new conversation');

        $id = (int) basename((string) parse_url($redirect, \PHP_URL_PATH));
        $chat = $this->em()->getRepository(Chat::class)->find($id);
        self::assertInstanceOf(Chat::class, $chat);

        return $chat;
    }

    private function onlyExchange(): ChatExchange
    {
        $em = $this->em();
        $em->clear();
        $all = $em->getRepository(ChatExchange::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    /** @param list<string> $tags */
    private function seedTool(string $name, array $tags): void
    {
        $em = $this->em();

        // Reused across seeds: `mcp_server.name` is unique, and a test that
        // seeds two tools wants one server, not a constraint violation.
        $server = $em->getRepository(McpServer::class)->findOneBy(['name' => 'srv']);
        if (!$server instanceof McpServer) {
            $server = new McpServer('srv', 'http://127.0.0.1:9999/mcp', ServerProtocol::Mcp, null);
            $em->persist($server);
            $em->flush();
        }

        $tool = new Tool($server, $name, 'A test tool', ['type' => 'object']);
        $tool->setTags($tags);
        $em->persist($tool);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
