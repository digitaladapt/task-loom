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
