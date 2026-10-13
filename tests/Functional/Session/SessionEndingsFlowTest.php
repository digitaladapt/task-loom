<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Command\RunRequeueCommand;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\RunTrigger;
use App\Entity\SessionDeclarationOutcome;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Llm\LlmStop;
use App\Llm\LlmStopReason;
use App\Llm\LlmStreamMetrics;
use App\Message\SessionTurnMessage;
use App\Repository\RunRepository;
use App\Repository\ToolRepository;
use App\RunEngine\LoopState;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolboxSnapshot;
use App\Session\SessionEndings;
use App\Session\SessionSliceEngine;
use App\Session\SessionState;
use App\Session\SessionUndeclared;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The endings constitution, end to end (docs/design/SESSION_ENDINGS.md):
 * which declarations a session may make is part of its frozen constitution,
 * what silence means is a policy the slice carries in its checkpoint, and
 * both are enforced where the design note says they must be — the wire the
 * model sees, the settle path, and the derived state the sweep reads.
 *
 * The runs are driven through the real lanes and the real slice engine, as
 * in SessionSliceFlowTest; slices whose constitution predates the run are
 * crafted exactly as the slice engine crafts them (the same frozen-snapshot
 * + checkpoint shape), because "a slice with a constitution the deployment
 * no longer agrees with" is precisely the state these tests must pin.
 */
#[AllowMockObjectsWithoutExpectations]
final class SessionEndingsFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemoryRevision')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemory')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);

        $container = static::getContainer();
        $this->engine = new RunEngine(
            $this->llm,
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $container->get(\App\RunEngine\ToolExecutorInterface::class),
            $container->get(\App\Context\ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(RunGraph::class),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
            $this->bus(),
            $container->get(\App\Session\SessionMemoryRenderer::class),
            $container->get(\App\Session\SessionToolRunner::class),
            $container->get(SessionSliceEngine::class),
        );

        $container->set(RunEngine::class, $this->engine);
    }

    /**
     * The wire carries the FILTERED declaration and the prompt teaches the
     * same set; a declaration outside it is refused with feedback naming the
     * alternatives — and the model, told why, declares what it can
     * (SESSION_ENDINGS.md §3, layers one and two).
     */
    public function testTheWireCarriesAFilteredDeclarationAndRefusesWhatItCannotMake(): void
    {
        $task = $this->sessionTask();
        $slice = $this->craftSlice($task, new SessionEndings(endings: 'milestone'), RunTrigger::Manual);
        $this->em->persist($slice);
        $this->em->flush();

        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages, array $tools) use (&$chats): LlmResponse {
                $chats[] = [$messages, $tools];

                return match (\count($chats)) {
                    // The model reaches for `done` — which this session does
                    // not have. The refusal comes back as feedback.
                    1 => $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Trying to finish.']],
                    ]),
                    // Told why, it declares the ending it does have.
                    2 => $this->response(toolCalls: [
                        ['id' => 'c2', 'name' => 'session_declare', 'arguments' => ['outcome' => 'milestone', 'summary' => 'One step further.']],
                    ]),
                    // The launched successor (the deployment's own endings).
                    default => $this->response(toolCalls: [
                        ['id' => 'c3', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'The deployment could finish it.']],
                    ]),
                };
            },
        );

        $this->pump();

        self::assertCount(3, $chats, 'refusal, declaration, successor');

        // The wire: session_declare's enum admits exactly the frozen set,
        // and its description says there is no completion.
        $declare = $this->declarationDescriptor($chats[0][1]);
        self::assertSame(['milestone'], $declare['function']['parameters']['properties']['outcome']['enum'] ?? null);
        self::assertStringContainsString('no completion', (string) ($declare['function']['description'] ?? ''));

        // The refusal the model saw on its next request names the problem
        // and the alternative — not a bare enum violation.
        $feedback = $this->toolFeedback($chats[1][0]);
        self::assertArrayHasKey('c1', $feedback);
        self::assertStringContainsString('not available', $feedback['c1']);
        self::assertStringContainsString('no completion', $feedback['c1']);
        self::assertStringContainsString('milestone', $feedback['c1']);

        // And the loop settled on what the session could actually declare.
        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(2, $runs);
        self::assertSame(SessionDeclarationOutcome::Milestone, $runs[0]->getDeclarationOutcome());
        self::assertSame(SessionDeclarationOutcome::Done, $runs[1]->getDeclarationOutcome());

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Complete, $slices->state($this->taskRefetch($task)));
    }

    /**
     * `undeclared: continue` (SESSION_ENDINGS.md §4): a slice that ends with
     * a message and no declaration settles SUCCEEDED — the message is its
     * Completion artifact — with a typed `undeclared` marker for the digest,
     * and the next slice launches in the same commit, exactly as a milestone
     * does.
     */
    public function testAContinuedSliceSettlesSucceededAndLaunchesTheNextSliceInTheSameCommit(): void
    {
        $task = $this->sessionTask();
        $slice = $this->craftSlice(
            $task,
            new SessionEndings(undeclared: 'continue'),
            RunTrigger::Manual,
            undeclared: SessionUndeclared::Continue,
            maxUndeclared: 3,
        );
        $this->em->persist($slice);
        $this->em->flush();

        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        $chats = [];
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use (&$chats): LlmResponse {
                $chats[] = $messages;

                // No tool call: the slice ends by talking, which under
                // `continue` is a legitimate settlement.
                return 1 === \count($chats)
                    ? $this->response(content: 'Chipped away at the importer; state is in my notes.')
                    : $this->response(toolCalls: [
                        ['id' => 'c1', 'name' => 'session_declare', 'arguments' => ['outcome' => 'done', 'summary' => 'Wrapped up.']],
                    ]);
            },
        );

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(2, $runs, 'the continuation launched exactly one successor');

        $settled = $runs[0];
        self::assertSame(RunStatus::Succeeded, $settled->getStatus(), 'under continue an undeclared ending is a settlement, not a failure');
        self::assertNull($settled->getDeclarationOutcome());
        self::assertSame('Chipped away at the importer; state is in my notes.', $this->completionResultOf($settled), 'the message IS the slice artifact');
        self::assertSame(RunTrigger::Continuation, $runs[1]->getTriggeredBy());

        // The typed marker: the pattern is countable apart from ordinary
        // completions.
        $marker = null;
        foreach ($settled->getEvents() as $event) {
            if (RunEventType::Undeclared === $event->getType()) {
                $marker = $event->getPayload();
            }
        }
        self::assertIsArray($marker);
        self::assertSame(1, $marker['streak'] ?? null);
        self::assertSame(3, $marker['max'] ?? null);

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Complete, $slices->state($this->taskRefetch($task)));
        self::assertCount(0, $this->lane('session'), 'no carrier is left behind');
    }

    /**
     * The counted streak is the loop bound (SESSION_ENDINGS.md §4): the Nth
     * consecutive undeclared settlement stops the session for a human —
     * loud, and with nothing launched.
     */
    public function testTheStreakCeilingStopsASessionThatKeepsEndingUndeclared(): void
    {
        $task = $this->sessionTask();

        // One undeclared continuation already in history.
        $first = $this->craftSlice($task, new SessionEndings(undeclared: 'continue'), RunTrigger::Manual, undeclared: SessionUndeclared::Continue, maxUndeclared: 2);
        $first->markSucceeded();
        $this->em->persist($first);
        $this->em->flush();

        // The slice settling now would be the second — at the ceiling.
        $second = $this->craftSlice($task, new SessionEndings(undeclared: 'continue'), RunTrigger::Continuation, undeclared: SessionUndeclared::Continue, maxUndeclared: 2);
        $this->em->persist($second);
        $this->em->flush();

        $this->bus()->dispatch(new SessionTurnMessage((int) $second->getId(), 1));

        $this->llm->method('chat')->willReturn($this->response(content: 'Still going, quietly.'));

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(2, $runs, 'at the ceiling, nothing is launched');

        $settled = $runs[1];
        self::assertSame(RunStatus::Incomplete, $settled->getStatus());
        self::assertNull($settled->getDeclarationOutcome());

        $reason = $this->failureReasonOf($settled);
        self::assertIsString($reason);
        self::assertStringContainsString('2 consecutive undeclared settlements', $reason, 'the count is stated as the human will see it');
        self::assertStringContainsString('stops for a human', $reason);

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Stopped, $slices->state($this->taskRefetch($task)));
    }

    /**
     * An empty terminal message still stops even under `continue`: a slice
     * that ends saying nothing is a stall, not a settlement.
     */
    public function testAnEmptyMessageStillStopsUnderContinue(): void
    {
        $task = $this->sessionTask();
        $slice = $this->craftSlice($task, new SessionEndings(undeclared: 'continue'), RunTrigger::Manual, undeclared: SessionUndeclared::Continue, maxUndeclared: 3);
        $this->em->persist($slice);
        $this->em->flush();

        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));
        $this->llm->method('chat')->willReturn($this->response(content: '   '));

        $this->pump();

        $this->em->clear();
        $settled = $this->runs()[0];
        self::assertSame(RunStatus::Incomplete, $settled->getStatus());
        $reason = $this->failureReasonOf($settled);
        self::assertIsString($reason);
        self::assertStringContainsString('saying nothing is a stall', $reason);
    }

    /**
     * The derived state honours the policy the slice froze: a succeeded,
     * undeclared slice under `continue` derives `owed` — and the requeue
     * sweep repairs a lost launch exactly as it does for a milestone
     * (SESSION_ENDINGS.md §4, "the same shape, so the sweep needs no new
     * story").
     */
    public function testTheRequeueSweepLaunchesAnOwedSliceAfterAnUndeclaredContinuation(): void
    {
        $task = $this->sessionTask();

        // The lost-launch shape: a slice that settled as a continuation,
        // with no successor row.
        $settled = $this->craftSlice($task, new SessionEndings(undeclared: 'continue'), RunTrigger::Manual, undeclared: SessionUndeclared::Continue, maxUndeclared: 3);
        $settled->markSucceeded();
        $this->em->persist($settled);
        $this->em->flush();

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Owed, $slices->state($this->taskRefetch($task)), 'a continuation owes the next slice');

        $tester = new CommandTester(static::getContainer()->get(RunRequeueCommand::class));

        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('would launch the next slice', $tester->getDisplay());
        self::assertCount(1, $this->runs(), 'dry run must create nothing');

        $tester->execute([]);
        self::assertStringContainsString('launched the next slice', $tester->getDisplay());

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(2, $runs);
        self::assertSame(RunTrigger::Continuation, $runs[1]->getTriggeredBy());
        self::assertSame(RunStatus::Queued, $runs[1]->getStatus());
        self::assertCount(1, $this->lane('session'), 'its first turn is committed with the row');

        // Converged: a second sweep finds nothing to do.
        $tester->execute(['--dry-run' => true]);
        self::assertStringNotContainsString('would launch', $tester->getDisplay());
    }

    /**
     * The prompt the slice freezes teaches exactly the frozen set — bullets
     * for the endings it has, an explicit line when it has no completion,
     * and the policy's own sentence about silence (SESSION_ENDINGS.md §3,
     * layer three: the five restatements collapse to one derivation).
     */
    public function testTheSlicePromptTeachesOnlyTheFrozenEndings(): void
    {
        $task = $this->sessionTask();
        $endings = new SessionEndings(endings: 'milestone,blocked', undeclared: 'continue', maxUndeclared: 3);

        $head = static::getContainer()->get(PromptCompiler::class)->compileForSlice($task, [], $endings);
        $system = $head['system'];

        self::assertStringContainsString('- milestone —', $system);
        self::assertStringContainsString('- blocked —', $system);
        self::assertStringNotContainsString('- done —', $system);
        self::assertStringContainsString('no completion', $system);
        self::assertStringContainsString('settled as a continuation', $system);
        self::assertStringContainsString('3 consecutive undeclared settlements', $system);
        self::assertStringContainsString('milestone / blocked', $system, 'the note names the admissible outcomes');
    }

    /**
     * The streaming guards meet the endings policy (the merge of
     * `SESSION_ENDINGS.md` with the streaming-capture work): a terminal
     * message a guard CUT SHORT is not a settlement under any policy —
     * the harness stopped a generation that misbehaved, the model did not
     * choose to end its slice, and continuing past the cut would defeat
     * the very guard that fired. It stops the session for a human, with
     * the stop's own class and detail visible on the failure — even under
     * `continue`, where an ordinary undeclared message would have been a
     * legitimate continuation.
     */
    public function testAGuardCutTerminalMessageStopsEvenUnderContinue(): void
    {
        $task = $this->sessionTask();
        $slice = $this->craftSlice(
            $task,
            new SessionEndings(undeclared: 'continue'),
            RunTrigger::Manual,
            undeclared: SessionUndeclared::Continue,
            maxUndeclared: 3,
        );
        $this->em->persist($slice);
        $this->em->flush();

        $this->bus()->dispatch(new SessionTurnMessage((int) $slice->getId(), 1));

        // The partial content exists — under `continue` an ordinary message
        // like this would settle as a continuation — but the stop rides it.
        $this->llm->method('chat')->willReturn(new LlmResponse(
            content: 'I was partway through explaining the importer when ',
            finishReason: '',
            toolCalls: [],
            usage: [],
            reasoningContent: null,
            durationMs: 1234,
            stop: new LlmStop(LlmStopReason::DurationExceeded, 'still producing output after 600s and 2 extension(s) (TASKLOOM_LLM_SOFT_DEADLINE / _SOFT_EXTENSION / _MAX_EXTENSIONS) — 812 chunk(s) so far'),
            metrics: new LlmStreamMetrics(chunks: 812, firstDeltaMs: 40, firstContentMs: 40, elapsedMs: 1234, extensions: 2),
        ));

        $this->pump();

        $this->em->clear();
        $runs = $this->runs();
        self::assertCount(1, $runs, 'a cut launches nothing — the guard fired, and a human looks');

        $settled = $runs[0];
        self::assertSame(RunStatus::Incomplete, $settled->getStatus());
        self::assertNull($settled->getDeclarationOutcome());

        $reason = $this->failureReasonOf($settled);
        self::assertIsString($reason);
        self::assertStringContainsString('cut by a guard', $reason);
        self::assertStringContainsString('SOFT_DEADLINE', $reason, 'the stop\'s own detail is the story');

        // The stop's class rides the FAILURE, not the completion.
        $failureClass = null;
        foreach ($settled->getEvents() as $event) {
            if (RunEventType::Failure === $event->getType()) {
                $failureClass = $event->getErrorClass();
            }
        }
        self::assertSame(\App\Entity\ErrorClass::LlmDurationExceeded, $failureClass);

        $slices = static::getContainer()->get(SessionSliceEngine::class);
        self::assertSame(SessionState::Stopped, $slices->state($this->taskRefetch($task)));
    }

    // --------------------------------------------------------------- helpers

    private function sessionTask(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /**
     * A slice crafted exactly as {@see SessionSliceEngine::createSlice()}
     * crafts one — the same frozen snapshot and checkpoint shape — with the
     * endings and policy given, so tests can pin a constitution the current
     * deployment would no longer produce.
     */
    private function craftSlice(
        Task $task,
        SessionEndings $endings,
        RunTrigger $trigger,
        ?SessionUndeclared $undeclared = null,
        int $maxUndeclared = SessionEndings::DEFAULT_MAX_UNDECLARED,
    ): Run {
        $container = static::getContainer();
        $tools = (new ToolboxResolver($container->get(ToolRepository::class), $endings))->resolve($task);

        $run = new Run($task);
        $run->setTriggeredBy($trigger);
        $run->setToolboxSnapshot(ToolboxSnapshot::fromDefinitions($tools));
        $run->setCheckpoint((new LoopState(
            stepBudget: 25,
            toolRetries: 2,
            circuitBreakerThreshold: 3,
            promptHead: $container->get(PromptCompiler::class)->compileForSlice($task, $tools, $endings),
            undeclared: $undeclared ?? $endings->undeclared,
            maxUndeclared: $maxUndeclared,
        ))->toCheckpoint());

        return $run;
    }

    /**
     * The `session_declare` descriptor from a request's tool list.
     *
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private function declarationDescriptor(array $tools): array
    {
        foreach ($tools as $tool) {
            if (isset($tool['function']['name']) && 'session_declare' === $tool['function']['name']) {
                return $tool;
            }
        }

        self::fail('session_declare was not offered on the wire.');
    }

    /**
     * The tool-role messages of a request's conversation, keyed by call id.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, string>
     */
    private function toolFeedback(array $messages): array
    {
        $feedback = [];
        foreach ($messages as $message) {
            if ('tool' === ($message['role'] ?? null)) {
                $feedback[(string) ($message['tool_call_id'] ?? '')] = (string) ($message['content'] ?? '');
            }
        }

        return $feedback;
    }

    private function taskRefetch(Task $task): Task
    {
        $fresh = $this->em->getRepository(Task::class)->find($task->getId());
        \assert($fresh instanceof Task);

        return $fresh;
    }

    private function completionResultOf(Run $run): ?string
    {
        foreach ($run->getEvents() as $event) {
            if (RunEventType::Completion === $event->getType()) {
                $result = $event->getPayload()['result'] ?? null;

                return \is_string($result) ? $result : null;
            }
        }

        return null;
    }

    private function failureReasonOf(Run $run): ?string
    {
        foreach ($run->getEvents() as $event) {
            if (RunEventType::Failure === $event->getType()) {
                $reason = $event->getPayload()['reason'] ?? null;

                return \is_string($reason) ? $reason : null;
            }
        }

        return null;
    }

    private function pump(int $maxDeliveries = 50): void
    {
        for ($i = 0; $i < $maxDeliveries; ++$i) {
            $delivered = $this->deliverOne('session');
            $delivered = $this->deliverOne('tools') || $delivered;

            if (!$delivered) {
                return;
            }
        }

        self::fail('The lanes did not settle within 50 deliveries.');
    }

    private function deliverOne(string $lane): bool
    {
        $envelopes = iterator_to_array($this->transport($lane)->get());
        if ([] === $envelopes) {
            return false;
        }

        $envelope = $envelopes[array_key_first($envelopes)];
        $this->bus()->dispatch($envelope->with(new ReceivedStamp($lane), new ConsumedByWorkerStamp()));
        $this->transport($lane)->ack($envelope);

        return true;
    }

    /**
     * @return list<object>
     */
    private function lane(string $lane): array
    {
        $messages = [];
        foreach ($this->transport($lane)->get(1000) as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }

    private function bus(): MessageBusInterface
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function transport(string $lane): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.'.$lane);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<Run> */
    private function runs(): array
    {
        return $this->em->createQuery('SELECT r FROM App\Entity\Run r ORDER BY r.id ASC')->getResult();
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}>|null $toolCalls
     */
    private function response(?string $content = null, ?array $toolCalls = null): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            finishReason: null === $toolCalls ? 'stop' : 'tool_calls',
            toolCalls: $toolCalls ?? [],
            usage: ['total_tokens' => 10],
            reasoningContent: null,
            durationMs: 5,
        );
    }
}
