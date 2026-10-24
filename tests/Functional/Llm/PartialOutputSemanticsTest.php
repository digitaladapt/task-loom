<?php

declare(strict_types=1);

namespace App\Tests\Functional\Llm;

use App\Entity\Chat;
use App\Entity\ChatEventType;
use App\Entity\ChatExchangeStatus;
use App\Entity\ErrorClass;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Llm\LlmStop;
use App\Llm\LlmStopReason;
use App\Llm\LlmStreamMetrics;
use App\Repository\ChatExchangeEventRepository;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Partial output is output: the streaming-capture semantics as they land in
 * the two ledgers (the change's decision record).
 *
 * A generation a guard cut short returns the partial content as the answer
 * — the person sees what was said rather than an error where nothing is
 * shown — and the stop (reason, detail, dropped tool calls) is recorded on
 * the response event with its error class. A cut that captured *nothing*
 * stays a failure carrying its named reason, because an empty answer is
 * indistinguishable from silence.
 *
 * The LLM is stubbed at its interface; everything else is the real engine,
 * the real ledger, and the real chat loop.
 */
#[AllowMockObjectsWithoutExpectations]
final class PartialOutputSemanticsTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)

    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchangeEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ChatExchange')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Chat')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StreamEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);

        // The engines resolve the client from the container, so the stub
        // must be registered there — exactly as the other engine tests do.
        static::getContainer()->set(LlmClientInterface::class, $this->llm);
    }

    /**
     * Build a response the way the streaming client does for a cut: partial
     * content, no tool calls, a stop with its evidence, and metrics.
     */
    private function cutResponse(string $partial, LlmStopReason $reason, string $detail, int $chunks = 400): LlmResponse
    {
        return new LlmResponse(
            content: $partial,
            finishReason: '',
            toolCalls: [],
            usage: [],
            reasoningContent: null,
            durationMs: 1234,
            stop: new LlmStop($reason, $detail),
            metrics: new LlmStreamMetrics(
                chunks: $chunks,
                firstDeltaMs: 40,
                firstContentMs: 40,
                elapsedMs: 1234,
                extensions: 0,
            ),
        );
    }

    // ── Chat ────────────────────────────────────────────────────────────

    public function testACutChatReplyIsDeliveredAsTheReplyWithTheStopRecordedInTheLedger(): void
    {
        $engine = static::getContainer()->get('test.service_container')->get(\App\Chat\ChatEngine::class);

        $chat = new Chat('Partial-output conversation');
        $this->em->persist($chat);

        /** @var \App\Chat\ChatEngine $engine */
        $exchange = $engine->ask($chat, 'Tell me a story');
        $this->em->flush();

        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturn($this->cutResponse(
                'Once upon a time there was a loom that kept weaving the same thread ',
                LlmStopReason::LoopDetected,
                'output stopped progressing: a 36-character block 7 times (252 chars, "kept weaving the same thread ")',
            ));

        $status = $engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Answered, $status, 'a partial reply is a reply');

        $this->em->clear();
        $fresh = $this->em->find(\App\Entity\ChatExchange::class, $exchange->getId());
        self::assertNotNull($fresh);

        $events = static::getContainer()->get(ChatExchangeEventRepository::class)->findForExchange($fresh);

        $reply = null;
        $response = null;
        foreach ($events as $event) {
            if (ChatEventType::Reply === $event->getType()) {
                $reply = $event;
            }
            if (ChatEventType::LlmResponse === $event->getType()) {
                $response = $event;
            }
        }

        self::assertNotNull($reply, 'the partial text reached the transcript');
        self::assertStringContainsString('once upon a time', strtolower((string) $reply->getContent()));
        self::assertStringContainsString('same thread', (string) $reply->getContent());

        self::assertNotNull($response, 'the response event is the ledger record');
        self::assertSame(ErrorClass::LlmLoopDetected, $response->getErrorClass(), 'the stop class rides the response event');

        $payload = $response->getPayload();
        self::assertSame('loop_detected', $payload['stopped']['reason'] ?? null);
        self::assertStringContainsString('same thread', (string) ($payload['stopped']['detail'] ?? ''));
        self::assertSame(400, $payload['metrics']['chunks'] ?? null, 'the timing facts are recorded');
    }

    public function testACutWithNoCapturedOutputIsAFailureWithTheNamedReason(): void
    {
        $engine = static::getContainer()->get('test.service_container')->get(\App\Chat\ChatEngine::class);

        $chat = new Chat('Silent endpoint conversation');
        $this->em->persist($chat);

        $exchange = $engine->ask($chat, 'Are you there?');
        $this->em->flush();

        // Nothing captured: the client throws rather than returning empty
        // content with a stop — and the exception carries the guard's class.
        $stop = new LlmStop(
            LlmStopReason::FirstTokenTimeout,
            'no output at all within 90s (TASKLOOM_LLM_FIRST_TOKEN_TIMEOUT) — prefill, a queue, or a wedged server; nothing was generated',
        );
        $this->llm->expects(self::once())
            ->method('chat')
            ->willThrowException(\App\Llm\LlmRequestException::streamStop($stop));

        $status = $engine->reply((int) $exchange->getId());

        self::assertSame(ChatExchangeStatus::Failed, $status, 'a cut that captured nothing is not a partial answer');
    }

    // ── Runs ────────────────────────────────────────────────────────────

    public function testACutRunCompletionStillCompletesWithThePartialResultAndRecordsTheStop(): void
    {
        $server = new \App\Entity\McpServer('test-server', 'https://server.example/mcp', \App\Entity\ServerProtocol::Mcp);
        $this->em->persist($server);
        $tool = new \App\Entity\Tool($server, 'get_weather', 'test tool', ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']], [$server->getName()]);
        $this->em->persist($tool);

        $task = new Task(
            title: 'Summarize the log',
            brief: 'Summarize the log in one paragraph.',
            kind: TaskKind::Run,
            toolboxMode: ToolboxMode::Explicit,
            toolbox: ['get_weather'],
            createdBy: TaskAuthor::User,
        );
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        $this->llm->expects(self::once())
            ->method('chat')
            ->willReturn($this->cutResponse(
                'The log shows three restarts, all at 03:00, which suggests the cron sweep ',
                LlmStopReason::DurationExceeded,
                'still producing output after 600s and 2 extension(s) (TASKLOOM_LLM_SOFT_DEADLINE / _SOFT_EXTENSION / _MAX_EXTENSIONS) — 812 chunk(s) so far',
            ));

        $run = $this->runEngine()->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus(), 'partial output is output: the run completes with what was said');

        $types = [];
        $responseEvent = null;
        foreach ($run->getEvents() as $event) {
            $types[] = $event->getType()->value;
            if ('llm_response' === $event->getType()->value) {
                $responseEvent = $event;
            }
        }

        self::assertContains('completion', $types);
        self::assertNotNull($responseEvent);
        self::assertSame(ErrorClass::LlmDurationExceeded, $responseEvent->getErrorClass(), 'the truncation is visible on the response event, not silent');
        self::assertSame('duration_exceeded', $responseEvent->getPayload()['stopped']['reason'] ?? null);

        $completion = null;
        foreach ($run->getEvents() as $event) {
            if ('completion' === $event->getType()->value) {
                $completion = $event;
            }
        }
        self::assertNotNull($completion);
        self::assertStringContainsString('three restarts', (string) ($completion->getPayload()['result'] ?? ''));
    }

    public function testACutRunWithNoCapturedOutputFailsWithTheGuardClass(): void
    {
        $server = new \App\Entity\McpServer('test-server', 'https://server.example/mcp', \App\Entity\ServerProtocol::Mcp);
        $this->em->persist($server);
        $tool = new \App\Entity\Tool($server, 'get_weather', 'test tool', ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']], [$server->getName()]);
        $this->em->persist($tool);

        $task = new Task(
            title: 'Silent model run',
            brief: 'Do the thing.',
            kind: TaskKind::Run,
            toolboxMode: ToolboxMode::Explicit,
            toolbox: ['get_weather'],
            createdBy: TaskAuthor::User,
        );
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        $stop = new LlmStop(
            LlmStopReason::SilenceTimeout,
            'output stalled for 120s mid-generation (TASKLOOM_LLM_SILENCE_TIMEOUT) after 0 chunk(s) over 120.0s — the endpoint is not producing',
        );
        $this->llm->expects(self::once())
            ->method('chat')
            ->willThrowException(\App\Llm\LlmRequestException::streamStop($stop));

        $run = $this->runEngine()->run($task);

        self::assertNotSame(RunStatus::Succeeded, $run->getStatus());
        self::assertSame(ErrorClass::LlmSilenceTimeout, $run->getErrorClass());
    }

    private function runEngine(): RunEngine
    {
        $container = static::getContainer();

        $executor = $this->createMock(ToolExecutorInterface::class);

        return new RunEngine(
            $this->llm,
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $executor,
            $container->get(\App\Context\ContextWindow::class),
            $container->get(\App\Repository\RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(RunGraph::class),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
        );
    }
}
