<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Chat\ChatEngine;
use App\Chat\ChatToolbox;
use App\Entity\Chat;
use App\Entity\ChatOrigin;
use App\Security\AdminUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The JavaScript a page loads must cover the widgets that page renders.
 *
 * This exists because of a live bug, found in production and only reproducible
 * in a browser: the toolbox picker's behaviour lived in `task-editor.js`, which
 * is the *task editor's* entrypoint — but the toolbox widget is also rendered by
 * both chat pages, which load only the shell (`app`). On `/chat` and
 * `/chat/{id}` the mode radios therefore did nothing at all, and the panel the
 * server had already marked `hidden` was never corrected: a picker whose mode
 * was `tags` could sit showing the explicit-tools panel.
 *
 * Nothing in PHP could see this. The markup was correct, the declaration was
 * correct, the field names were correct — the only wrong thing was which file
 * the browser had been given. So the guard has to be about the *page's script
 * set*, asserted through the preloads and module tag the browser itself obeys.
 *
 * Asserting on the importmap JSON alone would not have caught it either: the
 * importmap is one document-wide map of everything the app *has*, and it names
 * `task-editor` on the chat pages too. It is the entrypoint tag and the
 * modulepreload links that say what the page actually pulls in.
 */
#[AllowMockObjectsWithoutExpectations]
final class PageScriptsTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private int $chatId = 0;

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());

        // A conversation to look at. Created through the engine rather than by
        // hand so this test breaks if the aggregate's shape changes, instead of
        // silently testing a page that no longer resembles a real one.
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach (['ChatExchangeEvent', 'ChatExchange', 'Chat'] as $entity) {
            $em->createQuery('DELETE FROM App\\Entity\\'.$entity)->execute();
        }
        $em->flush();

        $engine = static::getContainer()->get(ChatEngine::class);
        \assert($engine instanceof ChatEngine);
        $chat = $engine->start('a conversation', ChatOrigin::Web, ChatToolbox::none());
        $this->chatId = (int) $chat->getId();
        $em->clear();
    }

    /**
     * The picker's behaviour is in the shell entrypoint, so every page that
     * renders the widget has it.
     */
    public function testTheShellEntrypointCoversTheToolboxPicker(): void
    {
        $this->assertPageLoads('/chat', ['app', 'toolbox'], 'the new-conversation picker');
        $this->assertPageLoads('/chat/'.$this->chatId, ['app', 'toolbox'], 'a conversation');
    }

    /**
     * The control: this page was always right and must stay right. It is the
     * page whose script set the chat pages were wrongly assumed to share.
     */
    public function testTheTaskEditorStillLoadsItsOwnEntrypoint(): void
    {
        $this->assertPageLoads('/tasks/new', ['app', 'toolbox', 'task-editor'], 'the task editor');
    }

    /** And a conversation should still not be dragging in the step builder. */
    public function testTheChatPagesDoNotDragInTheTaskEditor(): void
    {
        $this->client->request('GET', '/chat');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertDoesNotMatchRegularExpression(
            '#<link rel="modulepreload" href="[^"]*task-editor-#',
            $html,
            'A conversation should not be loading the step-graph builder.',
        );
    }

    /**
     * @param list<string> $expected logical module names the page must load
     */
    private function assertPageLoads(string $path, array $expected, string $label): void
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful(\sprintf('%s should render', $label));

        $available = $this->loadedModules((string) $this->client->getResponse()->getContent());

        foreach ($expected as $module) {
            self::assertContains(
                $module,
                $available,
                \sprintf(
                    "%s should load the '%s' module.\nLoaded: %s",
                    $label,
                    $module,
                    [] === $available ? '(nothing)' : implode(', ', $available),
                ),
            );
        }
    }

    /**
     * The logical names of the modules a page pulls in.
     *
     * Hashed filenames are resolved back through the page's own importmap,
     * because that is the only place the mapping exists — and splitting them off
     * the filename text ("take everything before the last dash") is guesswork
     * that gets `task-editor-I84g1Yt.js` wrong, since the logical name contains
     * a dash of its own.
     *
     * @return list<string>
     */
    private function loadedModules(string $html): array
    {
        preg_match('#<script type="importmap"[^>]*>(.*?)</script>#s', $html, $importmap);
        $decoded = json_decode(trim((string) ($importmap[1] ?? '')), true);
        self::assertIsArray($decoded, 'the page should carry an importmap to resolve modules through');

        /** @var array<string, string> $imports */
        $imports = $decoded['imports'] ?? [];
        $nameByPath = [];
        foreach ($imports as $key => $published) {
            // A key is either an entrypoint alias ('app') or a source path
            // ('/assets/toolbox.js'); the logical name is the basename without
            // its extension either way.
            $nameByPath[$published] = pathinfo(basename($key), \PATHINFO_FILENAME);
        }

        // Modules the browser is told to fetch eagerly...
        preg_match_all('#<link rel="modulepreload" href="([^"]+)"#', $html, $preloads);
        $loaded = [];
        foreach ($preloads[1] as $href) {
            if (isset($nameByPath[$href])) {
                $loaded[] = $nameByPath[$href];
            }
        }

        // ...and the entrypoint tag, which is the `import 'app';` module. Those
        // names are aliases already, so they need no resolution.
        preg_match('#<script type="module"[^>]*>(.*?)</script>#s', $html, $entry);
        preg_match_all("/import '([^']+)'/", (string) ($entry[1] ?? ''), $imported);
        $loaded = array_merge($loaded, $imported[1]);

        return array_values(array_unique($loaded));
    }
}
