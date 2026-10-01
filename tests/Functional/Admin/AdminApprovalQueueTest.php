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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin approval-queue surface (SPEC §8) over real HTTP: auth
 * boundary, lifecycle actions (enable/approve/reject/archive), and the
 * toolbox preview that must flag a task whose tags resolve to nothing
 * (the 'core' incident: an LLM-authored task declared a tag no tool
 * carries; enabling it produced a run that failed at dispatch).
 */
final class AdminApprovalQueueTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->flush();
        $em->clear();
    }

    public function testUnauthenticatedListIsRejected(): void
    {
        // Fresh client, no session: the firewall sends the visitor to sign in.
        static::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertTrue($client->getResponse()->isRedirect('/login'));
    }

    public function testWrongPasswordIsRejected(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        // Sign in with the wrong password: back to the form, with the error.
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', '/login', ['_token' => $token, 'password' => 'wrong-password']);
        self::assertTrue($client->getResponse()->isRedirect('/login'));

        // And the session is not established.
        $client->request('GET', '/', server: ['HTTP_ACCEPT' => 'text/html']);
        self::assertTrue($client->getResponse()->isRedirect('/login'));
    }

    public function testHealthStaysPublicWithAuthConfigured(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/health');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testListShowsApprovalQueueAndEnabled(): void
    {
        $this->makeDraft('A draft', 'agent', TaskAuthor::Agent);
        $enabled = $this->makeDraft('An enabled one', 'user', TaskAuthor::User);
        $enabled->enable();
        $this->tasks()->save($enabled);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#approval-heading', 'Approval queue');
        self::assertStringContainsStringIgnoringCase('a draft', $this->client->getResponse()->getContent());
        self::assertStringContainsStringIgnoringCase('an enabled one', $this->client->getResponse()->getContent());
    }

    public function testDetailShowsToolboxPreviewWithUnknownTagProblem(): void
    {
        $task = $this->makeDraft('Tagged task', 'b', TaskAuthor::Agent);
        $task->setToolbox(['core']);
        $this->tasks()->save($task);

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsStringIgnoringCase(
            'No tool carries the tag &quot;core&quot;',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertStringContainsStringIgnoringCase(
            'empty toolbox',
            $this->client->getResponse()->getContent(),
        );
    }

    public function testEnablePromotesDraft(): void
    {
        $task = $this->makeDraft('To enable', 'b', TaskAuthor::Agent);

        $this->postAction($task, 'enable');

        self::assertResponseRedirects();
        $task = $this->refetch($task);
        self::assertTrue($task->isEnabled());
    }

    public function testEnableFailsWithoutCsrfToken(): void
    {
        $task = $this->makeDraft('No csrf', 'b', TaskAuthor::Agent);

        // Visit the page first (as a real admin would), then POST without
        // the token.
        $this->client->request('GET', '/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/tasks/'.$task->getId().'/enable');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $task = $this->refetch($task);
        self::assertFalse($task->isEnabled());
    }

    public function testApproveSwapsReplacement(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->setTitle('Improved');
        $this->tasks()->save($draft);

        $this->postAction($draft, 'approve');

        self::assertResponseRedirects();
        $original = $this->refetch($original);
        $draft = $this->refetch($draft);
        self::assertTrue($draft->isEnabled());
        self::assertTrue($original->isArchived());
        self::assertTrue($original->isSuperseded());
        self::assertSame($draft->getId(), $original->getSupersededBy()->getId());
    }

    public function testRejectArchivesDraftButOriginalUntouched(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $this->tasks()->save($draft);

        $this->postAction($draft, 'reject');

        self::assertResponseRedirects();
        $original = $this->refetch($original);
        $draft = $this->refetch($draft);
        self::assertTrue($original->isEnabled());
        self::assertTrue($draft->isArchived());
        self::assertFalse($draft->isEnabled());
    }

    /**
     * SPEC §4.4 — after the swap, the approved replacement is the live task
     * and must present itself as one: **Run now**, not Approve/Reject. The
     * entity keeps its replacementFor pointer for the record's history, so
     * the template has to distinguish "pending replacement draft" (offer the
     * decision) from "approved replacement" (a running task).
     */
    public function testApprovedReplacementShowsRunNowNotTheApprovalActions(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->setTitle('Improved');
        $this->tasks()->save($draft);

        $this->postAction($draft, 'approve');
        self::assertResponseRedirects();

        $this->client->request('GET', '/tasks/'.$draft->getId());
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Run now', $content, 'an approved replacement is a runnable task');
        self::assertStringNotContainsString('Approve replacement', $content, 'its approval moment has passed');
        self::assertStringNotContainsString('>Reject<', $content, 'there is no draft left to reject');
        self::assertStringContainsString('/tasks/'.$draft->getId().'/run', $content);
    }

    /**
     * The counterpart: the proposal still gets its decision buttons while it
     * is pending. (Guards against "fix by removing the buttons everywhere".).
     */
    public function testPendingReplacementStillShowsTheApprovalActions(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $this->tasks()->save($draft);

        $this->client->request('GET', '/tasks/'.$draft->getId());
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Approve replacement', $content);
        self::assertStringContainsString('>Reject<', $content);
        self::assertStringNotContainsString('/tasks/'.$draft->getId().'/run', $content, 'a disabled draft is not runnable');
    }

    /**
     * And the guard behind the button: a stale POST of reject to an approved
     * replacement must not archive the task the swap just made live.
     */
    public function testRejectPostToAnApprovedReplacementIsRefusedGracefully(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $this->tasks()->save($draft);

        // Pull a valid task-reject token from an actual pending draft, then
        // aim it at the approved replacement (as a re-submitted form would).
        $other = $original->createReplacementDraft(TaskAuthor::Agent);
        $other->setTitle('Another idea');
        $this->tasks()->save($other);
        $crawler = $this->client->request('GET', '/tasks/'.$other->getId());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action*="/reject"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        // Now approve the first draft (which archives the original, so the
        // second draft's original is superseded — irrelevant to this POST).
        $this->postAction($draft, 'approve');
        self::assertResponseRedirects();

        $this->client->request('POST', '/tasks/'.$draft->getId().'/reject', ['_token' => $token]);

        // A graceful error redirect, not a 500 — and the live task survives.
        self::assertResponseRedirects();
        $draft = $this->refetch($draft);
        self::assertTrue($draft->isEnabled(), 'the live task stays enabled');
        self::assertFalse($draft->isArchived(), 'a stale reject must not archive a running task');
    }

    public function testArchiveDiscardsDraft(): void
    {
        $task = $this->makeDraft('Throwaway', 'b', TaskAuthor::Agent);

        $this->postAction($task, 'archive');

        self::assertResponseRedirects();
        $task = $this->refetch($task);
        self::assertTrue($task->isArchived());
    }

    public function testEnableReplacementDraftIsRefused(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $this->tasks()->save($draft);

        // The detail page shows approve/reject (not enable) for a
        // replacement draft, so pull a valid task-enable token from another
        // draft's page (same session, same intent) and POST directly.
        $other = $this->makeDraft('Unrelated draft', 'b', TaskAuthor::Agent);
        $crawler = $this->client->request('GET', '/tasks/'.$other->getId());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action*="/enable"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/tasks/'.$draft->getId().'/enable', ['_token' => $token]);

        // Graceful error redirect, not a 500 — and the draft stays disabled.
        self::assertResponseRedirects();
        $draft = $this->refetch($draft);
        self::assertFalse($draft->isEnabled());
    }

    /**
     * The pause (SPEC §4.4): an enabled task is disabled from the UI, and it
     * stops being runnable without losing its record — the schedule and run
     * history stay, and Enable brings it straight back.
     */
    public function testDisablePausesAnEnabledTask(): void
    {
        $task = $this->enabledTask('Pause me');
        $this->runOnce($task); // give it history worth keeping

        // An enabled task offers the pause, alongside Run now.
        $this->client->request('GET', '/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('>Disable<', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('/tasks/'.$task->getId().'/disable', (string) $this->client->getResponse()->getContent());

        $this->postAction($task, 'disable');

        self::assertResponseRedirects();
        $task = $this->refetch($task);
        self::assertFalse($task->isEnabled());
        self::assertFalse($task->isArchived(), 'disabling is a pause, not a discard');
        self::assertSame('0 8 * * *', $task->getSchedule(), 'the schedule survives the pause');

        $this->client->request('GET', '/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('>Enable<', $content, 'a paused task offers Enable again');
        self::assertStringNotContainsString('>Run now<', $content, 'a paused task is not runnable');
        self::assertStringContainsString('disabled', $content, 'the status says the task was paused, not left as a draft');
    }

    public function testDisableFailsWithoutCsrfToken(): void
    {
        $task = $this->enabledTask('No csrf');

        $this->client->request('GET', '/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/tasks/'.$task->getId().'/disable');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $task = $this->refetch($task);
        self::assertTrue($task->isEnabled(), 'a bad token must not pause anything');
    }

    /**
     * Disable is only for enabled tasks — a draft has nothing to pause, and a
     * stale POST must be refused gracefully rather than flipping a flag that
     * was never on.
     */
    public function testDisableIsRefusedOnADraft(): void
    {
        $draft = $this->makeDraft('Still a draft', 'b', TaskAuthor::User);

        // A draft's detail page correctly shows no Disable button, so mint a
        // valid task-disable token from an enabled task's page (same session,
        // same intent) and POST it at the draft.
        $crawler = $this->client->request('GET', '/tasks/'.$this->enabledTask('Runnable sibling')->getId());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action*="/disable"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/tasks/'.$draft->getId().'/disable', ['_token' => $token]);

        self::assertResponseRedirects();
        $draft = $this->refetch($draft);
        self::assertFalse($draft->isEnabled());
        self::assertFalse($draft->isArchived(), 'a refused disable does not discard the draft');
    }

    /**
     * Run now and the scheduler both read the same enabled flag, so a paused
     * task is refused by the manual trigger too (SPEC §4.2) — the pause holds
     * until Enable.
     */
    public function testRunNowRefusesAPausedTask(): void
    {
        $task = $this->enabledTask('Paused then triggered');
        $this->postAction($task, 'disable');
        self::assertResponseRedirects();

        // Mint a valid task-run token from a different enabled task's page
        // (same session, same intent) and aim it at the paused one.
        $other = $this->enabledTask('Runnable sibling');
        $crawler = $this->client->request('GET', '/tasks/'.$other->getId());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action*="/run"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/tasks/'.$task->getId().'/run', ['_token' => $token]);

        self::assertResponseRedirects('/tasks/'.$task->getId());
        $this->client->followRedirect();
        self::assertStringContainsString('not enabled', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->refetch($task)->isEnabled(), 'the refused run does not resume the task');
    }

    /**
     * The corner the pause creates: an approved replacement (its swap done,
     * so not pending) that is then disabled. Its page must offer Enable — not
     * Run now, not the approval actions, and no Discard — because it is a
     * live task that was paused, not a proposal and not a throwaway draft.
     */
    public function testADisabledApprovedReplacementOffersOnlyEnable(): void
    {
        $original = $this->makeDraft('Original', 'b', TaskAuthor::User);
        $original->enable();
        $this->tasks()->save($original);

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $this->tasks()->save($draft);
        $this->postAction($draft, 'approve');
        self::assertResponseRedirects();

        $this->runOnce($draft); // it is a live task with a past
        $this->postAction($draft, 'disable');
        self::assertResponseRedirects();

        $this->client->request('GET', '/tasks/'.$draft->getId());
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('>Enable<', $content, 'a paused live task resumes with Enable');
        self::assertStringContainsString('disabled', $content, 'its status says it was paused');
        self::assertStringNotContainsString('Run now', $content, 'it is paused, so it is not runnable');
        self::assertStringNotContainsString('Approve replacement', $content, 'its approval moment has passed');
        self::assertStringNotContainsString('>Reject<', $content);
        self::assertStringNotContainsString('Discard draft', $content, 'a paused live task is not a throwaway draft');
    }

    /**
     * Re-fetch by id: entities go detached between requests in the test
     * client, so assertions read a fresh managed copy.
     */
    private function refetch(Task $task): Task
    {
        $fresh = $this->tasks()->find($task->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * Visit the task page (as a human would), pull the CSRF token out of
     * the rendered form, then POST the lifecycle action.
     */
    private function postAction(Task $task, string $action): void
    {
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter(\sprintf('form[action*="/%s"]', $action))->form();

        $this->client->submit($form);
    }

    private function makeDraft(string $title, string $brief, TaskAuthor $author): Task
    {
        $task = new Task($title, $brief, TaskKind::Run, ToolboxMode::Tags, ['core'], $author);
        $this->tasks()->save($task);

        return $task;
    }

    private function enabledTask(string $title): Task
    {
        $task = $this->makeDraft($title, 'b', TaskAuthor::User);
        $task->setSchedule('0 8 * * *');
        $task->enable();
        $this->tasks()->save($task);

        return $task;
    }

    /**
     * Give a task a terminal run row, so its detail page renders as a task
     * that has a past (the disabled-vs-plain-draft distinction).
     */
    private function runOnce(Task $task): void
    {
        $em = $this->em();
        $run = new \App\Entity\Run($this->refetch($task));
        $run->markSucceeded();
        $em->persist($run);
        $em->flush();
    }

    private function tasks(): TaskRepository
    {
        $tasks = $this->client->getContainer()->get(TaskRepository::class);
        \assert($tasks instanceof TaskRepository);

        return $tasks;
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
