<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\TaskRepository;
use App\Security\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The menu bar (SPEC §8): one top-level navigation on every signed-in page.
 *
 * Before this, each template grew its own <nav> with whatever links that page
 * needed — the tool catalog was reachable from exactly one page, the
 * attention queue from two — so the answer to "where can I go from here"
 * depended on where you already were. These tests pin the three things that
 * make the bar a bar: it is on every page, it carries the same items, and it
 * says where you are.
 */
final class AdminMenuBarTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $em->flush();
        $em->clear();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signedInPages(): iterable
    {
        yield 'task list' => ['/'];
        yield 'runs' => ['/runs'];
        yield 'attention' => ['/attention'];
        yield 'tool catalog' => ['/tools'];
        yield 'new task' => ['/tasks/new'];
        yield 'task detail' => ['/tasks/{id}'];
        yield 'task edit' => ['/tasks/{id}/edit'];
    }

    #[DataProvider('signedInPages')]
    public function testEverySignedInPageCarriesTheSameMenu(string $path): void
    {
        $path = str_replace('{id}', (string) $this->makeDraft('Menu target')->getId(), $path);

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $items = $crawler->filter('#site-menu .site-menu-list a')->each(
            static fn (Crawler $link): string => $link->text(),
        );

        self::assertSame(
            ['Tasks', 'Runs', 'Attention', 'Tool catalog', 'New task'],
            $items,
            'the menu bar offers the same sections on every page, in the same order',
        );

        // The sign-out control rides in the same bar — one place that answers
        // "where can I go", not a separate strip above it.
        self::assertNotNull(
            $crawler->filter('form[data-signout] input[name="_csrf_token"]')->attr('value'),
            'the menu bar carries the sign-out form',
        );
    }

    public function testTheCurrentPageIsMarkedInTheMenu(): void
    {
        $this->client->request('GET', '/runs');

        $current = $this->client->getCrawler()->filter('#site-menu a[aria-current]');
        self::assertCount(1, $current);
        self::assertSame('Runs', $current->text());
        self::assertSame('page', $current->attr('aria-current'));
    }

    /**
     * A sub-page is *in* a section, but it is not the section's own target:
     * /tasks/47 belongs under Tasks while the Tasks item points at /, so the
     * marker is `true` (containing section) rather than `page` (exact match).
     * `aria-current="page"` on an item that navigates elsewhere is the
     * classic lie this distinction exists to avoid.
     */
    public function testASubPageMarksItsSectionWithoutClaimingToBeTheExactPage(): void
    {
        $task = $this->makeDraft('A sub-page');

        $this->client->request('GET', '/tasks/'.$task->getId());

        $current = $this->client->getCrawler()->filter('#site-menu a[aria-current]');
        self::assertCount(1, $current);
        self::assertSame('Tasks', $current->text());
        self::assertSame('true', $current->attr('aria-current'));
    }

    /**
     * The editor is reached from a task but is not the task list: it must
     * still say which section the operator is in, not fall back to marking
     * nothing.
     */
    public function testTheEditorMarksItsSectionToo(): void
    {
        $task = $this->makeDraft('Edited');

        $this->client->request('GET', '/tasks/'.$task->getId().'/edit');

        self::assertSame('true', $this->client->getCrawler()
            ->filter('#site-menu a[aria-current]')
            ->attr('aria-current'));
    }

    /**
     * The login page is the one page with no session, so it gets no bar at
     * all — nothing about the app's shape is visible before credentials.
     */
    public function testTheLoginPageHasNoMenu(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filter('#site-menu'));
        self::assertCount(0, $client->getCrawler()->filter('[data-signout]'));
    }

    /**
     * The pages that used to carry their own <nav> must not have grown a
     * second one: two navigation landmarks with the same purpose is exactly
     * the inconsistency this change removes. One <nav> per page, and it is
     * the menu bar's.
     */
    public function testPagesDoNotCarryASecondNavigationLandmark(): void
    {
        foreach (['/', '/runs', '/attention', '/tools'] as $path) {
            $this->client->request('GET', $path);

            $navs = $this->client->getCrawler()->filter('nav');
            self::assertCount(1, $navs, \sprintf('%s carries exactly one <nav> (the menu bar)', $path));
            self::assertSame('site-menu', $navs->attr('class'));
        }
    }

    /**
     * The disclosure control ships in the markup and is animated by the shell
     * script: without it the header is never marked collapsible, so the panel
     * stays a plain visible list and the toggle stays hidden. That contract is
     * what keeps the bar usable when the script does not run, so the markup
     * has to keep offering both halves.
     */
    public function testTheCollapsibleControlIsWiredToItsPanel(): void
    {
        $this->client->request('GET', '/');

        $crawler = $this->client->getCrawler();
        $toggle = $crawler->filter('[data-site-menu] [data-menu-toggle]');

        self::assertCount(1, $toggle);
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertSame(
            $crawler->filter('[data-menu-panel]')->attr('id'),
            $toggle->attr('aria-controls'),
            'the toggle names the region it actually opens',
        );
        self::assertCount(1, $crawler->filter('[data-menu-panel] nav.site-menu'), 'the panel contains the nav landmark');
    }

    private function makeDraft(string $title): Task
    {
        $task = new Task($title, 'A brief.', TaskKind::Run, ToolboxMode::Tags, ['core'], TaskAuthor::User);
        $this->tasks()->save($task);

        return $task;
    }

    private function tasks(): TaskRepository
    {
        $tasks = static::getContainer()->get(TaskRepository::class);
        \assert($tasks instanceof TaskRepository);

        return $tasks;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
