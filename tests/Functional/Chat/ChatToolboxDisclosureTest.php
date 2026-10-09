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
 * The chat toolbox picker is a disclosure, and it starts shut (SPEC §15.8).
 *
 * "Collapsed by default" is a UI preference and could be argued either way, so
 * what these tests pin is the part that is not a preference: a shut drawer must
 * still *say* what the toolbox is and must still *carry* it when the form is
 * saved. A picker that folded the answer away would be worse than the tall
 * picker it replaced — the design note's whole claim is that what she can do
 * right now is a thing on screen (`CHAT_TOOLS.md` §2.1).
 *
 * The loading-order trap this file exists to close: the summary's values come
 * from `values` and `errors`, which are macro arguments, and NOT from the
 * `{% set %}`s further down the macro, which Twig scopes to their own block
 * body and which therefore do not exist yet when the summary renders. Reading
 * them there is a `strict_variables` error in the test environment rather than
 * a blank summary, so the first test is a guard as much as an assertion.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatToolboxDisclosureTest extends WebTestCase
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
     * Both chat pages fold the picker, and it starts shut.
     *
     * The widget is still on the page and still named — the tabs, the radios
     * and the boxes are all in the DOM, so the page works with the script
     * absent and a browser's find-on-page still finds a tool name — but it is
     * inside a `<details>` with no `open` attribute.
     */
    public function testThePickerIsCollapsedOnBothChatPages(): void
    {
        $this->seedTool('get_weather', ['weather']);

        // A conversation is created first so the *list* page is looked at as a
        // page with something on it — the picker is rendered whether or not
        // there is a conversation beside it, but a realistic page is the one
        // worth asserting on.
        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);

        foreach (['/chat', '/chat/'.$chat->getId()] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful(\sprintf('%s should render', $path));

            $crawler = $this->client->getCrawler();
            self::assertCount(1, $crawler->filter('details.toolbox-disclosure'), $path.' folds the picker');
            self::assertCount(
                0,
                $crawler->filter('details.toolbox-disclosure[open]'),
                $path.' renders it collapsed, not expanded',
            );

            // Folded, but not gone: the same picker, with a summary.
            self::assertCount(1, $crawler->filter('details.toolbox-disclosure > summary'));
            self::assertCount(1, $crawler->filter('details.toolbox-disclosure fieldset.toolbox'), $path.' still renders the picker');
        }
    }

    /**
     * The summary states the answer, so a shut drawer is not a blank one.
     *
     * This is the property that makes "collapsed by default" acceptable at all:
     * the question the picker exists to answer — what can she do right now? — is
     * answered without opening it, and `+N` covers the tail of a long list.
     */
    public function testTheSummaryStatesTheChoiceWhileShut(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $this->seedTool('send_email', ['email']);

        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather', 'email']]);
        $this->settleLastExchange($chat);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $summary = $crawler->filter('details.toolbox-disclosure > summary')->text();

        self::assertStringContainsString('Toolbox', $summary);
        self::assertStringContainsString('by tag', $summary, 'the summary says which mode is active');
        self::assertStringContainsString('weather', $summary);
        self::assertStringContainsString('email', $summary);
    }

    /**
     * A long declaration is summarised, not truncated silently.
     *
     * Four tags is `three +1` — the count of what is not shown is on screen, so
     * the summary is a summary rather than a partial list pretending to be the
     * whole one.
     */
    public function testALongDeclarationIsCountedRatherThanCutOff(): void
    {
        foreach (['weather', 'email', 'calendar', 'notes'] as $tag) {
            $this->seedTool('tool_'.$tag, [$tag]);
        }

        $chat = $this->startChatWithTools([
            'toolbox_mode' => 'tags',
            'toolbox_tags' => ['weather', 'email', 'calendar', 'notes'],
        ]);
        $this->settleLastExchange($chat);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $summary = $crawler->filter('details.toolbox-disclosure > summary')->text();

        self::assertStringContainsString('+1', $summary, 'the unshown entry is counted');
        self::assertStringNotContainsString('notes', $summary, 'and the tail itself is not printed');
    }

    /**
     * Saving a collapsed picker does not lose the declaration.
     *
     * The real risk of folding a form is the one the DOM makes easy: an
     * unchecked box is not submitted, a cancelled radio is not submitted, and a
     * `disabled` control is not submitted — so a page that renders the choice
     * *from stored data* and submits *nothing* would read as "you chose no
     * tools", quietly rewriting her toolbox at the next message. This is the
     * regression test for that, and it goes through the real form the way a
     * browser does.
     */
    public function testASubmissionFromTheCollapsedFormKeepsTheDeclaration(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $this->seedTool('send_email', ['email']);

        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);
        $this->settleLastExchange($chat);

        // Read the page, then submit exactly what the browser would submit —
        // the form as rendered, with nothing re-ticked by hand.
        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $this->client->submit($crawler->selectButton('Send')->form(['message' => 'and again']));

        $this->em()->clear();
        $latest = $this->latestExchange($chat);
        self::assertSame(
            ['mode' => 'tags', 'declared' => ['weather']],
            $latest->getToolboxDeclaration(),
            'the collapsed picker submitted the declaration it was showing',
        );
        self::assertNotNull($latest->getToolboxSnapshot());
    }

    /**
     * An explicit-tools choice survives the same round trip, by name.
     */
    public function testAnExplicitSubmissionFromTheCollapsedFormKeepsTheDeclaration(): void
    {
        $this->seedTool('get_weather', ['weather']);

        $chat = $this->startChatWithTools(['toolbox_mode' => 'explicit', 'toolbox_tools' => ['get_weather']]);
        $this->settleLastExchange($chat);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $this->client->submit($crawler->selectButton('Send')->form(['message' => 'and again']));

        $this->em()->clear();
        self::assertSame(
            ['mode' => 'explicit', 'declared' => ['get_weather']],
            $this->latestExchange($chat)->getToolboxDeclaration(),
        );
    }

    /**
     * "No tools, chosen" is a choice, and it carries like any other.
     *
     * The mode is the half that is easy to lose: an empty declaration is
     * legitimate, but a submission that reproduces only the emptiness and not
     * the panel it was chosen from reopens the picker on the wrong panels —
     * `ChatToolbox::none()` documents why the mode is part of the answer.
     */
    public function testTheModeCarriesEvenWhenNothingIsDeclared(): void
    {
        $this->seedTool('get_weather', ['weather']);

        $chat = $this->startChatWithTools(['toolbox_mode' => 'explicit']);
        $this->settleLastExchange($chat);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());
        $this->client->submit($crawler->selectButton('Send')->form(['message' => 'and again']));

        $this->em()->clear();
        self::assertSame(
            ['mode' => 'explicit', 'declared' => []],
            $this->latestExchange($chat)->getToolboxDeclaration(),
        );
    }

    /**
     * A page with a reply pending is collapsed and wholly inert — every control
     * in the drawer is `disabled`, and the declaration is NOT carried in hidden
     * inputs.
     *
     * That absence is deliberate and is the one place the two rules interact.
     * A `disabled` control is not submitted at all, so hidden twins would be the
     * only thing that *did* submit — which would make this page the one page
     * where a message could change the toolbox, at exactly the moment the
     * toolbox is frozen. A submission with no toolbox fields at all is the
     * honest shape, and the controller refuses it by name ("the toolbox is
     * fixed").
     */
    public function testAPendingReplyRendersThePickerCollapsedAndInert(): void
    {
        $this->seedTool('get_weather', ['weather']);
        $chat = $this->startChatWithTools(['toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]);

        $crawler = $this->client->request('GET', '/chat/'.$chat->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('details.toolbox-disclosure'), 'still folded while pending');
        self::assertCount(0, $crawler->filter('details.toolbox-disclosure[open]'));
        self::assertGreaterThan(0, $crawler->filter('details.toolbox-disclosure input[disabled]')->count());
        self::assertCount(
            0,
            $crawler->filter('details.toolbox-disclosure input[type="hidden"]'),
            'an inert page carries nothing into a submission it will not make',
        );
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

    /**
     * Answer the newest exchange so the picker is live and shut rather than
     * pending and open.
     */
    private function settleLastExchange(Chat $chat): void
    {
        $em = $this->em();
        $this->latestExchange($chat)->markAnswered();
        $em->flush();
        $em->clear();
    }

    private function latestExchange(Chat $chat): ChatExchange
    {
        $exchange = $this->em()->getRepository(ChatExchange::class)->findOneBy(['chat' => $chat], ['id' => 'DESC']);
        self::assertInstanceOf(ChatExchange::class, $exchange);

        return $exchange;
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
