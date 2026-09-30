<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp\Server;

use App\Admin\RunDigest;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\RunTrigger;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The reviewer tools over real Streamable HTTP (SPEC §10, §11) — the same
 * wire traffic an external agent or a self-connected reviewer task sees.
 *
 * The assertions that matter:
 *   - the two tools are registered and advertised read-only, so a client
 *     can tell they will not mutate anything;
 *   - run_review reports the repetition it exists to find;
 *   - run_read_log's budget manifest is present even when nothing is elided;
 *   - a bad argument is a structured tool error naming the fix, not a 500.
 */
final class RunReviewMcpTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        // The MCP endpoint takes a bearer key (TASKLOOM_MCP_API_KEY).
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer test-mcp-api-key');

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
    }

    /**
     * The harness's own tool list must stay in sync with what the server
     * actually registers: run_review reports calls to those tools as
     * self-review, so a tool missing from the list would be silently
     * miscounted as third-party work.
     */
    public function testHarnessToolListCoversEveryRegisteredTool(): void
    {
        $sessionId = $this->initializeSession();
        $list = $this->rpc('tools/list', [], $sessionId);

        $registered = array_map(static fn (array $t): string => $t['name'], $list['body']['result']['tools']);

        foreach ($registered as $name) {
            self::assertContains(
                $name,
                RunDigest::HARNESS_TOOL_NAMES,
                \sprintf('"%s" is registered but missing from RunDigest::HARNESS_TOOL_NAMES — self-review would under-count it.', $name),
            );
        }
    }

    public function testReviewToolsAreAdvertisedAsReadOnly(): void
    {
        $sessionId = $this->initializeSession();
        $list = $this->rpc('tools/list', [], $sessionId);

        $byName = [];
        foreach ($list['body']['result']['tools'] as $tool) {
            $byName[$tool['name']] = $tool;
        }

        foreach (['run_review', 'run_read_log'] as $name) {
            self::assertArrayHasKey($name, $byName, $name.' must be registered');
            self::assertTrue($byName[$name]['annotations']['readOnlyHint'] ?? false, $name.' must advertise readOnlyHint');
        }
    }

    public function testRunReviewReportsRepeatedToolCalls(): void
    {
        $run = $this->ledgerRun();

        for ($i = 0; $i < 4; ++$i) {
            $this->append($run, RunEventType::ToolCall, ['tool' => 'calendar_list', 'arguments' => ['day' => 'today'], 'attempt' => 1]);
            $this->append($run, RunEventType::ToolResult, ['tool' => 'calendar_list', 'content' => '[]', 'isError' => false]);
        }
        $this->append($run, RunEventType::Completion, ['result' => 'briefed']);

        $call = $this->callTool('run_review', ['taskId' => $run->getTask()->getId()]);

        self::assertFalse($call['error'], $call['raw']);
        self::assertSame(4, $call['result']['funnel']['tool_calls']);
        self::assertSame(3, $call['result']['funnel']['repeated_calls']);
        self::assertSame(3, $call['result']['tools'][0]['identical_results']);
    }

    public function testRunReadLogAlwaysReportsItsBudgetManifest(): void
    {
        $run = $this->ledgerRun();
        $this->append($run, RunEventType::Completion, ['result' => 'the artifact']);

        $call = $this->callTool('run_read_log', ['runId' => $run->getId()]);

        self::assertFalse($call['error'], $call['raw']);
        self::assertSame(['artifact'], $call['result']['include']);
        self::assertArrayHasKey('budget', $call['result'], 'the manifest must be present even when nothing is elided');
        self::assertSame([], $call['result']['budget']['elided']);
        self::assertSame('the artifact', $call['result']['entries'][0]['text']);
    }

    public function testRunReadLogSurfacesReasoningWhenAsked(): void
    {
        $run = $this->ledgerRun();
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 1,
            'content' => '',
            'reasoningContent' => 'weighing the options',
            'usage' => [],
        ]);

        $call = $this->callTool('run_read_log', ['runId' => $run->getId(), 'include' => ['thinking']]);

        self::assertFalse($call['error'], $call['raw']);
        self::assertSame('weighing the options', $call['result']['entries'][0]['text']);
        self::assertSame('thinking', $call['result']['entries'][0]['type']);
    }

    /**
     * An unknown bucket is caught by the coarse schema gate before the
     * handler runs. Both layers are wanted: the schema rejects it cheaply
     * and names the allowed values, and the handler's own check (tested in
     * RunDigestTest) covers any path that reaches the service directly.
     */
    public function testRunReadLogUnknownBucketIsRejectedAndNamesTheAllowedValues(): void
    {
        $run = $this->ledgerRun();

        $call = $this->callTool('run_read_log', ['runId' => $run->getId(), 'include' => ['bogus']]);

        self::assertTrue($call['error'], 'an unknown bucket must not be silently ignored');
        self::assertStringContainsString('must be one of the allowed values', $call['raw']);
        foreach (['artifact', 'errors', 'tool_args', 'tool_results', 'thinking', 'prompt'] as $bucket) {
            self::assertStringContainsString($bucket, $call['raw'], 'the error must name the allowed buckets');
        }
    }

    public function testRunReviewForAMissingTaskIsAStructuredError(): void
    {
        $call = $this->callTool('run_review', ['taskId' => 999999]);

        self::assertTrue($call['error']);
        self::assertStringContainsString('No task with id 999999', $call['raw']);
    }

    /**
     * A run id from another task must be refused: silently digesting an
     * unrelated run would produce a confident, wrong review.
     */
    public function testRunReviewRefusesARunFromAnotherTask(): void
    {
        $run = $this->ledgerRun();

        $otherTask = new Task('Other', 'Brief.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $this->em->persist($otherTask);
        $this->em->flush();

        $call = $this->callTool('run_review', ['taskId' => $otherTask->getId(), 'runId' => $run->getId()]);

        self::assertTrue($call['error']);
        self::assertStringContainsString('belongs to task', $call['raw']);
    }

    public function testRunReviewBudgetBelowTheFloorIsRejectedByTheSchema(): void
    {
        $run = $this->ledgerRun();
        $sessionId = $this->initializeSession();

        $response = $this->rpc('tools/call', [
            'name' => 'run_review',
            'arguments' => ['taskId' => $run->getTask()->getId(), 'budgetChars' => 10],
        ], $sessionId);

        self::assertSame(200, $response['code']);
        self::assertArrayHasKey('error', $response['body'], 'the schema floor must reject a useless budget');
        self::assertSame(-32602, $response['body']['error']['code']);
    }

    /**
     * A task whose newest run is an abandoned one must default to the
     * settled run and say which was skipped.
     */
    public function testRunReviewSkipsAnIncompleteNewestRun(): void
    {
        $task = new Task('Skips crashes', 'Brief.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $this->em->persist($task);
        $this->em->flush();

        $settled = new Run($task);
        $settled->setRole(RunRole::Standalone);
        $settled->setTriggeredBy(RunTrigger::Manual);
        $settled->markStarted();
        $settled->markSucceeded();
        $this->em->persist($settled);
        $this->em->flush();
        $this->append($settled, RunEventType::Completion, ['result' => 'ok']);

        $crashed = new Run($task);
        $crashed->setRole(RunRole::Standalone);
        $crashed->setTriggeredBy(RunTrigger::Manual);
        $crashed->markStarted();
        $crashed->markIncomplete();
        $this->em->persist($crashed);
        $this->em->flush();

        $call = $this->callTool('run_review', ['taskId' => $task->getId()]);

        self::assertFalse($call['error'], $call['raw']);
        self::assertSame($settled->getId(), $call['result']['run']['id']);
        self::assertStringContainsString((string) $crashed->getId(), $call['result']['notes'][0]);
    }

    private function ledgerRun(): Run
    {
        $task = new Task('Review subject', 'Brief.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $this->em->persist($task);
        $this->em->flush();

        $run = new Run($task);
        $run->setRole(RunRole::Standalone);
        $run->setTriggeredBy(RunTrigger::Manual);
        $run->markStarted();
        $run->markSucceeded();
        $this->em->persist($run);
        $this->em->flush();

        $this->append($run, RunEventType::LlmRequest, ['step' => 1]);

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(Run $run, RunEventType $type, array $payload): void
    {
        $event = new RunEvent($type);
        $event->setPayload($payload);
        $run->appendEvent($event);
        $this->em->persist($event);
        $this->em->flush();
    }

    private function initializeSession(): string
    {
        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'review-test', 'version' => '1.0.0'],
        ]);

        self::assertSame(200, $init['code'], 'initialize failed: '.json_encode($init['body']));
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId, 'initialize must return an Mcp-Session-Id header.');

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{code: int, body: array<string, mixed>}
     */
    private function rpc(string $method, array $params, ?string $sessionId = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->client->request('POST', '/mcp', server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => random_int(1, 2 ** 30),
            'method' => $method,
            'params' => $params,
        ], \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();

        return [
            'code' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{error: bool, result: array<string, mixed>, raw: string}
     */
    private function callTool(string $name, array $arguments): array
    {
        $sessionId = $this->initializeSession();
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments], $sessionId);
        self::assertSame(200, $response['code'], json_encode($response['body']));

        $isError = (bool) ($response['body']['result']['isError'] ?? false);
        $contents = $response['body']['result']['content'] ?? [];
        $text = $contents[0]['text'] ?? '';
        $decoded = json_decode($text, true);

        // A JSON-RPC-level error (schema rejection) carries no
        // result.content, so its message lives in body.error.message.
        if (isset($response['body']['error'])) {
            return [
                'error' => true,
                'result' => [],
                'raw' => (string) ($response['body']['error']['message'] ?? json_encode($response['body'])),
            ];
        }

        return [
            'error' => $isError,
            'result' => \is_array($decoded) ? $decoded : [],
            'raw' => $text,
        ];
    }
}
