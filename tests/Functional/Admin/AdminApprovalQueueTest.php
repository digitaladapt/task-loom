<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\TaskRepository;
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
        $this->client->setServerParameter('PHP_AUTH_USER', 'admin');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test-admin-password');

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
        // Fresh client without the admin credentials.
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->setServerParameter('PHP_AUTH_USER', '');
        $client->setServerParameter('PHP_AUTH_PW', '');

        $client->request('GET', '/');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testWrongPasswordIsRejected(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->setServerParameter('PHP_AUTH_USER', 'admin');
        $client->setServerParameter('PHP_AUTH_PW', 'wrong-password');

        $client->request('GET', '/');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testHealthStaysPublicWithAuthConfigured(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->setServerParameter('PHP_AUTH_USER', '');
        $client->setServerParameter('PHP_AUTH_PW', '');

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
