<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\SessionMemory;
use App\Entity\SessionMemorySource;
use App\Entity\SessionMemoryTier;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\SessionMemoryRepository;
use App\Repository\TaskRepository;
use App\Security\AdminUser;
use App\Session\SessionMemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The session page (docs/design/SESSION_TASKS.md §6.1, build order step 6)
 * over real HTTP: the state block and its controls, and the operator's
 * steering — the objective, the notes, pin/tier, edit, delete, revert — all
 * landing through the single writer, visible in the revision history, and
 * taking effect on the next rendered page.
 *
 * The proof of "edits ride the session's next request" is deliberately
 * split: the block-rendering half lives in SessionMemoryRendererTest and
 * the live-request half in SessionMemoryInjectionTest; this suite proves
 * the UI writes are the same writes the block reads.
 */
final class AdminSessionPageTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryStore $store; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\SessionMemoryRevision')->execute();
        $em->createQuery('DELETE FROM App\Entity\SessionMemory')->execute();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\StreamEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->flush();
        $em->clear();

        $this->store = static::getContainer()->get(SessionMemoryStore::class);
    }

    public function testThePageShowsTheStateAndTheMemoryWithProvenanceAndTiers(): void
    {
        $task = $this->sessionTask();
        $this->store->setObjective($task, 'Finish the importer.', SessionMemorySource::Operator);
        $this->store->addNote($task, 'Migration 0007 adds the store.', SessionMemorySource::Session);
        $note = $this->store->addNote($task, 'Keep PRs small.', SessionMemorySource::Session);
        $this->store->setTier($note, SessionMemoryTier::Cold);

        $this->client->request('GET', '/tasks/'.$task->getId().'/session');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        // The state block: fresh, with the explanation and the Start control.
        self::assertStringContainsString('state: <strong>fresh</strong>', $content);
        self::assertStringContainsString('No slice has run yet', $content);
        self::assertStringContainsString('Start session', $content);

        // The memory: objective first, with provenance; hot and cold notes split.
        self::assertStringContainsString('Objective', $content);
        self::assertStringContainsString('[operator]', $content, 'the objective carries who set it');
        self::assertStringContainsString('Finish the importer.', $content);
        self::assertStringContainsString('Migration 0007 adds the store.', $content);
        self::assertStringContainsString('[you]', $content, 'session-written notes render as the session\'s own');
        self::assertStringContainsString('Notes — cold', $content);
        self::assertStringContainsString('Keep PRs small.', $content, 'a cold note is kept and shown, not injected');
    }

    public function testTheOperatorEditsTheObjectiveAndTheReplacedTextIsInTheHistory(): void
    {
        $task = $this->sessionTask();
        $this->store->setObjective($task, 'The original aim.', SessionMemorySource::Session);

        $this->post($task, '/session/objective', ['text' => 'Stop refactoring — the importer only.']);

        self::assertResponseRedirects('/tasks/'.$task->getId().'/session');
        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Stop refactoring — the importer only.', $content);
        // (Twig escapes the apostrophe as &#039;, so the assertion stops short of it.)
        self::assertStringContainsString('Saved — it will ride the session', $content);

        // Provenance flipped to the operator, and the superseded text is in the history.
        self::assertStringContainsString('History (1 replaced revision)', $content);
        self::assertStringContainsString('The original aim.', $content);

        $objective = $this->memories()->findObjectiveFor((int) $task->getId());
        self::assertNotNull($objective);
        self::assertSame(SessionMemorySource::Operator, $objective->getSource());
        self::assertSame(2, $objective->getRevision());
    }

    public function testNotesCanBeAddedEditedPinnedDemotedAndDeleted(): void
    {
        $task = $this->sessionTask();

        // Add.
        $this->post($task, '/session/notes', ['text' => 'A note from the operator.']);
        self::assertResponseRedirects();
        $note = $this->onlyNote($task);
        self::assertSame(SessionMemorySource::Operator, $note->getSource(), 'a UI note is the operator\'s, so the session is told to follow it');

        // Edit.
        $this->post($task, \sprintf('/session/memory/%d/edit', $note->getId()), ['text' => 'An edited note.']);
        self::assertSame('An edited note.', $this->refreshed($note)->getText());
        self::assertSame(2, $this->refreshed($note)->getRevision());
        self::assertCount(1, $this->memories()->findRevisionsForMemory($this->refreshed($note)), 'the superseded text is kept');

        // Pin: one act, keeps it hot and marks it pinned.
        $this->post($task, \sprintf('/session/memory/%d/pin', $note->getId()), ['pinned' => '1']);
        self::assertTrue($this->refreshed($note)->isPinned());
        self::assertTrue($this->refreshed($note)->isHot());

        // Unpin, then demote and promote explicitly.
        $this->post($task, \sprintf('/session/memory/%d/pin', $note->getId()), ['pinned' => '0']);
        self::assertFalse($this->refreshed($note)->isPinned());
        $this->post($task, \sprintf('/session/memory/%d/tier', $note->getId()), ['tier' => 'cold']);
        self::assertTrue($this->refreshed($note)->isCold());
        $this->post($task, \sprintf('/session/memory/%d/tier', $note->getId()), ['tier' => 'hot']);
        self::assertTrue($this->refreshed($note)->isHot());

        // Delete: gone, history included.
        $noteId = (int) $note->getId();
        $this->post($task, \sprintf('/session/memory/%d/delete', $note->getId()), []);
        self::assertSame([], $this->memories()->findNotesFor((int) $task->getId()));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM session_memory_revision WHERE memory_id = :id', ['id' => $noteId]));
    }

    public function testARevertAppliesThePriorTextAndKeepsTheReplacedOne(): void
    {
        $task = $this->sessionTask();
        $this->store->setObjective($task, 'First aim.', SessionMemorySource::Operator);
        $this->store->setObjective($task, 'Second aim.', SessionMemorySource::Session);

        $objective = $this->memories()->findObjectiveFor((int) $task->getId());
        self::assertNotNull($objective);
        $revisions = $this->memories()->findRevisionsForMemory($objective);
        self::assertCount(1, $revisions);
        $oldest = $revisions[0];

        $this->post($task, \sprintf('/session/memory/%d/revert/%d', $objective->getId(), $oldest->getId()), []);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Reverted to revision 1', $content);

        $current = $this->memories()->findObjectiveFor((int) $task->getId());
        self::assertNotNull($current);
        self::assertSame('First aim.', $current->getText());
        self::assertSame(SessionMemorySource::Operator, $current->getSource(), 'the revert is the operator\'s act now');
        self::assertCount(2, $this->memories()->findRevisionsForMemory($current), 'the revert kept what it replaced');
    }

    public function testAnOversizedWriteIsRefusedWithTheKnobsNameAndPersistsNothing(): void
    {
        $task = $this->sessionTask();

        $this->post($task, '/session/objective', ['text' => str_repeat('x', 5000)]);

        $this->client->followRedirect();
        self::assertStringContainsString('TASKLOOM_SESSION_WRITE_MAX_CHARS', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->memories()->findObjectiveFor((int) $task->getId()), 'a refused write persists nothing');
    }

    public function testTheActionsAreCsrfProtected(): void
    {
        $task = $this->sessionTask();

        $this->client->request('POST', '/tasks/'.$task->getId().'/session/notes', ['text' => 'sneaky']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->memories()->findNotesFor((int) $task->getId()));
    }

    public function testANonSessionTaskHasNoSessionPage(): void
    {
        $task = new Task('Ordinary', 'Do it.', TaskKind::Run, ToolboxMode::Tags, ['x'], TaskAuthor::User);
        $this->tasks()->save($task);

        $this->client->request('GET', '/tasks/'.$task->getId().'/session');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTaskPageLinksToTheSessionPage(): void
    {
        $task = $this->sessionTask();

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/tasks/'.$task->getId().'/session', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Start launches the first slice through the queue path — the same
     * launch Run now performs (docs/design/SESSION_TASKS.md §8) — and the
     * page then reflects the working state.
     */
    public function testStartLaunchesTheFirstSliceOnTheSessionLane(): void
    {
        $task = $this->sessionTask();

        $this->post($task, '/session/start', []);

        self::assertResponseRedirects('/tasks/'.$task->getId().'/session');
        $crawler = $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Slice queued', $content);
        $flash = $crawler->filter('p.flash')->first()->text();
        self::assertStringContainsString('"session" lane', $flash, 'the flash names the lane, unescaped');
        self::assertStringContainsString('A slice is in flight', $content, 'the state block now says what is happening');

        // One slice run exists. (Its carrier is asserted at the engine level
        // — SessionSliceFlowTest reads the in-memory lane directly; a web
        // test cannot, because Symfony's service resetter clears the
        // in-memory transports between requests, by design.)
        $runs = $this->em()->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
        self::assertCount(1, $runs);

        // And the control set flipped: Pause is offered, Start is not.
        self::assertStringContainsString('Pause', $content);
        self::assertStringNotContainsString('Start session', $content);
    }

    /**
     * Pause is the operator's switch off (§8): the slice already in flight
     * is untouched, but a next slice would no longer launch. The page then
     * shows the paused-mid-slice state and offers the plain re-enable.
     */
    public function testPauseDisablesAndThePageOffersReEnable(): void
    {
        $task = $this->sessionTask();
        $this->post($task, '/session/start', []);

        $this->post($task, '/session/pause', []);

        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('state: <strong>paused (working)</strong>', $content);
        self::assertStringContainsString('Paused mid-slice', $content);
        self::assertStringContainsString('Enable session', $content, 're-enabling a mid-slice pause is a pure unpause — nothing to launch');

        self::assertFalse($this->refreshedTask($task)->isEnabled());

        // Re-enable: enabled again, no new slice launched by the enable act itself.
        $this->post($task, '/session/enable', []);
        self::assertTrue($this->refreshedTask($task)->isEnabled());
        self::assertCount(1, $this->em()->createQuery('SELECT r FROM App\Entity\Run r')->getResult(), 'enabling does not launch');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, string> $fields
     */
    private function post(Task $task, string $suffix, array $fields): void
    {
        $path = '/tasks/'.$task->getId().$suffix;

        // The real form's token: fetch the page first, take the token of the
        // form that posts to this action — exactly what a browser sends.
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/session');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter(\sprintf('form[action="%s"]', $path));
        self::assertGreaterThan(0, $form->count(), 'the page must offer the form being posted');
        $token = $form->first()->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', $path, ['_token' => $token] + $fields);
    }

    private function sessionTask(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->tasks()->save($task);

        return $task;
    }

    private function onlyNote(Task $task): SessionMemory
    {
        $notes = $this->memories()->findNotesFor((int) $task->getId());
        self::assertCount(1, $notes);

        return $notes[0];
    }

    private function refreshed(SessionMemory $memory): SessionMemory
    {
        $fresh = $this->em()->find(SessionMemory::class, $memory->getId());
        self::assertInstanceOf(SessionMemory::class, $fresh);

        return $fresh;
    }

    private function refreshedTask(Task $task): Task
    {
        $fresh = $this->em()->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);

        return $fresh;
    }

    private function memories(): SessionMemoryRepository
    {
        return static::getContainer()->get(SessionMemoryRepository::class);
    }

    private function tasks(): TaskRepository
    {
        return static::getContainer()->get(TaskRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
