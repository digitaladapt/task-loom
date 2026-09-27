<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Message\LlmTurnMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The run surface over real HTTP (SPEC §8): the run history with its
 * attempt-ledger timeline (filterable by error class), the transcript, the
 * completion artifact, the scheduler view, the attention queue grouped by
 * error class — and Run now, the only trigger in v1, which must launch on
 * the worker lanes (never inline in a request).
 */
final class AdminRunSurfaceTest extends WebTestCase
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

    public function testUnauthenticatedRunSurfaceIsRejected(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->setServerParameter('PHP_AUTH_USER', '');
        $client->setServerParameter('PHP_AUTH_PW', '');

        $client->request('GET', '/runs');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRunListShowsSchedulerAndHistory(): void
    {
        $task = $this->enabledTask('Briefing');

        // A fresh claim: a worker is on the wire right now.
        $holding = new Run($task);
        $holding->markStarted();
        $this->em()->persist($holding);
        $this->em()->flush();

        // A stale claim: the worker is gone; recovery is due.
        $stale = new Run($task);
        $stale->markStarted();
        $this->em()->persist($stale);
        $this->em()->flush();

        // A queued run: waiting for the FIFO, no claim.
        $queued = new Run($task);
        $this->em()->persist($queued);
        $this->em()->flush();

        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :at WHERE id = :id',
            ['at' => time() - 45, 'id' => $holding->getId()],
        );
        $conn->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :at WHERE id = :id',
            ['at' => time() - 7200, 'id' => $stale->getId()],
        );
        $this->em()->clear();

        $this->client->request('GET', '/runs');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        // Scheduler: both claims surface with their lane and age; the
        // stale one is flagged for takeover, the fresh one is not.
        self::assertStringContainsString('Scheduler', $content);
        self::assertStringContainsString('Run #'.$holding->getId(), $content);
        self::assertStringContainsString('holding for', $content);
        self::assertStringContainsString('stale claim — takeover due', $content);
        self::assertStringContainsString('llm lane', $content);

        // The queued run waits; the FIFO is visible.
        self::assertStringContainsString('1 run(s) waiting', $content);
        self::assertStringContainsString('Run #'.$queued->getId(), $content);
        self::assertStringContainsString('queued', $content);

        // History lists the runs of the task.
        self::assertStringContainsString('Run history', $content);
    }

    public function testRunDetailRendersTimelineArtifactAndTranscript(): void
    {
        $task = $this->enabledTask('Ledger showcase');
        $run = new Run($task);
        $run->markStarted();
        $run->setCheckpoint(['promptHead' => [
            'system' => 'You are a careful agent.',
            'user' => 'Compose the briefing.',
        ]]);

        $request = $run->appendEvent(new RunEvent(RunEventType::LlmRequest));
        $request->setPayload(['step' => 1, 'exchangesSoFar' => 0]);

        $response = $run->appendEvent(new RunEvent(RunEventType::LlmResponse));
        $response->setPayload([
            'step' => 1,
            'finishReason' => 'tool_calls',
            'content' => 'Checking the weather now.',
            'usage' => ['total_tokens' => 42],
        ]);
        $response->setDurationMs(1300);

        $call = $run->appendEvent(new RunEvent(RunEventType::ToolCall));
        $call->setPayload(['tool' => 'get_weather', 'arguments' => ['location' => 'Berlin'], 'attempt' => 1]);

        $result = $run->appendEvent(new RunEvent(RunEventType::ToolResult));
        $result->setPayload(['tool' => 'get_weather', 'content' => 'Sunny, 21°C', 'isError' => false]);

        $completion = $run->appendEvent(new RunEvent(RunEventType::Completion));
        $completion->setPayload(['result' => 'The final briefing artifact.']);

        $run->markSucceeded();
        $this->em()->persist($run);
        $this->em()->flush();

        $this->client->request('GET', '/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        // Timeline: every ledger row with its type label.
        self::assertStringContainsString('Attempt ledger', $content);
        self::assertStringContainsString('Llm Request', $content);
        self::assertStringContainsString('Llm Response', $content);
        self::assertStringContainsString('Tool Call', $content);
        self::assertStringContainsString('Tool Result', $content);
        self::assertStringContainsString('1.3 s', $content);

        // The completion artifact is the run's deliverable (SPEC §13.4).
        self::assertStringContainsString('Completion artifact', $content);
        self::assertStringContainsString('The final briefing artifact.', $content);

        // Transcript: the frozen prompt head, the assistant content, the
        // tool call and its result — the full committed record.
        self::assertStringContainsString('You are a careful agent.', $content);
        self::assertStringContainsString('Compose the briefing.', $content);
        self::assertStringContainsString('Checking the weather now.', $content);
        self::assertStringContainsString('get_weather', $content);
        self::assertStringContainsString('Sunny, 21°C', $content);
    }

    public function testTimelineFiltersByErrorClass(): void
    {
        $task = $this->enabledTask('Filtered failures');
        $run = new Run($task);
        $run->markStarted();
        $run->markFailed(ErrorClass::ServerError);

        $request = $run->appendEvent(new RunEvent(RunEventType::LlmRequest));
        $request->setPayload(['step' => 1]);

        $result = $run->appendEvent(new RunEvent(RunEventType::ToolResult));
        $result->setPayload(['tool' => 'get_weather', 'detail' => 'server exploded']);
        $result->setErrorClass(ErrorClass::ServerError);

        $failure = $run->appendEvent(new RunEvent(RunEventType::Failure));
        $failure->setPayload(['reason' => 'the weather server is down']);
        $failure->setErrorClass(ErrorClass::ServerError);

        $this->em()->persist($run);
        $this->em()->flush();

        // Unfiltered: everything renders, and the filter control lists the
        // classes this run actually hit.
        $this->client->request('GET', '/runs/'.$run->getId());
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Llm Request', $content);
        self::assertStringContainsString('server_error', $content);
        self::assertStringContainsString('?error_class=server_error', $content);

        // Filtered: only the events carrying the class.
        $this->client->request('GET', '/runs/'.$run->getId().'?error_class=server_error');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('server exploded', $content);
        self::assertStringContainsString('the weather server is down', $content);
        self::assertStringNotContainsString('Llm Request', $content);

        // An unknown class is a 404 — the URL names a filter that does not
        // exist, not a silently-ignored typo.
        $this->client->request('GET', '/runs/'.$run->getId().'?error_class=no_such_class');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testParentRunDetailGroupsChildrenByLevelAndLabelsFinalConsumer(): void
    {
        $task = $this->draftTask('Stepped');
        $weather = $this->step($task, 1, 'Weather');
        $summary = $this->step($task, 2, 'Summary', [$weather->getId()]);
        $task->enable();
        $this->em()->flush();

        $parent = new Run($task);
        $parent->setRole(RunRole::Parent);
        $parent->markStarted();
        $this->em()->persist($parent);
        $this->em()->flush();

        $weatherChild = new Run($task);
        $weatherChild->setRole(RunRole::Step);
        $weatherChild->setParent($parent);
        $weatherChild->setStep($weather);
        $weatherChild->markStarted();
        $weatherChild->markSucceeded();
        $this->em()->persist($weatherChild);

        $summaryChild = new Run($task);
        $summaryChild->setRole(RunRole::Step);
        $summaryChild->setParent($parent);
        $summaryChild->setStep($summary);
        $summaryChild->markFailed(ErrorClass::LlmError);
        $this->em()->persist($summaryChild);

        $final = new Run($task);
        $final->setRole(RunRole::FinalConsumer);
        $final->setParent($parent);
        $final->markStarted();
        $final->markSucceeded();
        $this->em()->persist($final);

        $parent->settleAs(\App\Entity\RunStatus::Failed, ErrorClass::LlmError);
        $this->em()->flush();

        $this->client->request('GET', '/runs/'.$parent->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('parent run (step graph)', $content);
        self::assertStringContainsString('Level 1', $content);
        self::assertStringContainsString('Level 2', $content);
        self::assertStringContainsString('Weather', $content);
        self::assertStringContainsString('Summary', $content);
        self::assertStringContainsString('Final consumer', $content);
        self::assertStringContainsString('final consumer', $content);

        // Each child is reachable — its own run page.
        self::assertStringContainsString('/runs/'.$weatherChild->getId(), $content);
        self::assertStringContainsString('/runs/'.$summaryChild->getId(), $content);
        self::assertStringContainsString('/runs/'.$final->getId(), $content);
    }

    public function testStandaloneRunDetailHasNoStepGraphSection(): void
    {
        $task = $this->enabledTask('Single unit');
        $run = new Run($task);
        $run->markStarted();
        $run->markSucceeded();
        $this->em()->persist($run);
        $this->em()->flush();

        $this->client->request('GET', '/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('standalone run', $content);
        self::assertStringNotContainsString('Step graph', $content);
    }

    public function testAttentionQueueGroupsByErrorClassAndHidesGraphChildren(): void
    {
        $task = $this->enabledTask('Needs eyes');

        // A graph that tripped the circuit breaker: the parent carries the
        // diagnosis (SPEC §13.5); the child must not be a separate queue row.
        $parent = new Run($task);
        $parent->setRole(RunRole::Parent);
        $parent->markStarted();
        $parent->markNeedsAttention(ErrorClass::ServerError);
        $this->em()->persist($parent);
        $this->em()->flush();

        $child = new Run($task);
        $child->setRole(RunRole::Step);
        $child->setParent($parent);
        $child->markStarted();
        $child->markNeedsAttention(ErrorClass::ServerError);
        $this->em()->persist($child);
        $this->em()->flush();

        // A budget-incomplete standalone run has no error class: it must
        // not vanish — it lands in the unclassified group.
        $incomplete = new Run($task);
        $incomplete->markStarted();
        $incomplete->markIncomplete();
        $this->em()->persist($incomplete);
        $this->em()->flush();

        $this->client->request('GET', '/attention');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('server_error', $content);
        self::assertStringContainsString('Run #'.$parent->getId(), $content);
        self::assertStringContainsString('Unclassified (no error class)', $content);
        self::assertStringContainsString('Run #'.$incomplete->getId(), $content);

        // The child of the settled graph is inside its parent's page, not a
        // queue row of its own.
        self::assertStringNotContainsString('/runs/'.$child->getId(), $content);
    }

    public function testRunNowQueuesOnTheLlmLaneAndRedirectsToTheRun(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->enabledTask('Run now target');

        $crawler = $this->client->request('GET', '/tasks/'.$task->getId());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action*="/run"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/tasks/'.$task->getId().'/run', ['_token' => $token]);

        // Redirect to the run's page; the run itself is queued, its first
        // turn on the llm lane — never executed inside the request.
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/runs/\d+#', $location);

        $lane = $this->transport('llm');
        self::assertCount(1, $lane->getSent());
        self::assertInstanceOf(LlmTurnMessage::class, $lane->getSent()[0]->getMessage());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('queued', $content);
        self::assertStringContainsString('llm', $content);

        // Exactly one run row was created; it is queued, not running.
        $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        $runs = $this->em()->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
        self::assertCount(1, $runs);
        self::assertSame(\App\Entity\RunStatus::Queued, $runs[0]->getStatus());
    }

    public function testRunNowOnASteppedTaskLaunchesTheGraph(): void
    {
        $this->catalogTool('get_weather');
        $task = $this->draftTask('Stepped run now');
        $this->step($task, 1, 'Fetch');
        $task->enable();
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/tasks/'.$task->getId());
        $token = $crawler->filter('form[action*="/run"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/tasks/'.$task->getId().'/run', ['_token' => $token]);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');

        // A root-step message per step is on the llm lane, dispatched inside
        // the graph's creation transaction.
        $lane = $this->transport('llm');
        self::assertCount(1, $lane->getSent());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('parent run (step graph)', $content);
        self::assertStringContainsString('Step graph', $content);
        self::assertStringContainsString('Level 1', $content);
        self::assertStringContainsString('Fetch', $content);

        // Parent + step child.
        $runs = $this->em()->createQuery('SELECT r FROM App\Entity\Run r ORDER BY r.id ASC')->getResult();
        self::assertCount(2, $runs);
        self::assertSame(RunRole::Parent, $runs[0]->getRole());
        self::assertSame(RunRole::Step, $runs[1]->getRole());
        self::assertSame($task->getId(), $runs[0]->getTask()->getId());
    }

    public function testRunNowRefusesWithoutCsrfToken(): void
    {
        $task = $this->enabledTask('No csrf');

        $this->client->request('POST', '/tasks/'.$task->getId().'/run');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $runs = $this->em()->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
        self::assertCount(0, $runs);
    }

    public function testRunNowRefusesADisabledTask(): void
    {
        $enabled = $this->enabledTask('Token source');
        $draft = $this->draftTask('Not enabled');

        $crawler = $this->client->request('GET', '/tasks/'.$enabled->getId());
        $token = $crawler->filter('form[action*="/run"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/tasks/'.$draft->getId().'/run', ['_token' => $token]);

        // Graceful flash + redirect, not a 500 — and no run row.
        self::assertResponseRedirects('/tasks/'.$draft->getId());
        $runs = $this->em()->createQuery('SELECT r FROM App\Entity\Run r')->getResult();
        self::assertCount(0, $runs);
    }

    public function testTaskListShowsTheLatestRunPerTask(): void
    {
        $task = $this->enabledTask('Has runs');
        $noRuns = $this->enabledTask('No runs');

        $first = new Run($task);
        $first->markStarted();
        $first->markSucceeded();
        $this->em()->persist($first);
        $this->em()->flush();

        $second = new Run($task);
        $second->markStarted();
        $second->markIncomplete();
        $this->em()->persist($second);
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('last run', $content);
        self::assertStringContainsString('/runs/'.$second->getId(), $content);
        self::assertStringContainsString('incomplete', $content);
        self::assertStringContainsString('no runs yet', $content);
    }

    public function testUnknownRunIs404(): void
    {
        $this->client->request('GET', '/runs/999999');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // --------------------------------------------------------------- helpers

    private function catalogTool(string $name): void
    {
        $server = new \App\Entity\McpServer('test-server', 'https://server.example/mcp', \App\Entity\ServerProtocol::Mcp);
        $this->em()->persist($server);

        $tool = new \App\Entity\Tool($server, $name, 'test tool', [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ], [$server->getName()]);
        $this->em()->persist($tool);
        $this->em()->flush();
    }

    private function transport(string $lane): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.'.$lane);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function enabledTask(string $title): Task
    {
        $task = $this->draftTask($title);
        $task->enable();
        $this->em()->flush();

        return $task;
    }

    private function draftTask(string $title): Task
    {
        $task = new Task($title, 'Compose.', TaskKind::Run, ToolboxMode::Tags, ['test-server'], TaskAuthor::User);
        $this->em()->persist($task);
        $this->em()->flush();

        return $task;
    }

    /**
     * @param list<int> $dependsOn
     */
    private function step(Task $task, int $position, string $title, array $dependsOn = []): Step
    {
        $step = new Step($task, $position, $title, 'Do '.$title.'.', ToolboxMode::Tags, ['test-server'], $dependsOn);
        $this->em()->persist($step);
        $this->em()->flush();

        return $step;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
