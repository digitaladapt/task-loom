<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Context\ContextExhaustedException;
use App\Context\ContextWindow;
use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Entity\Tool;
use App\Llm\LlmClientInterface;
use App\Llm\LlmRequestException;
use App\Message\LlmTurnMessage;
use App\Message\ToolTurnMessage;
use App\Repository\RunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The run engine (SPEC §3, §5, §6): compile prompt → LLM → (validate →
 * execute → append results) → checkpoint → repeat, until a justified
 * completion declaration or a budget failure. Every exchange is a typed
 * RunEvent row in the attempt ledger (§5.3), persisted before the next LLM
 * request (checkpoint, §5.5).
 *
 * The engine is TURN-BASED: one LLM exchange is two deliverable units — an
 * LLM turn (one model request) and a tool turn (the calls of that
 * exchange). Each turn is a short-lived call that reloads the run's whole
 * state from the row (checkpoint + snapshot), so a fresh worker is never
 * assumed (§6). run() drives the same turns inline (the synchronous path
 * behind app:run:now); start() + the Messenger handlers drive them across
 * two lanes, where the number of `llm` workers IS the
 * TASKLOOM_LLM_MAX_CONCURRENCY semaphore.
 *
 * Delivery contract — the part that makes at-least-once safe:
 *
 *   1. A turn's state commit and its successor message are written in the
 *      SAME database transaction (`doctrine://default` puts the transport
 *      table on the connection this engine flushes to). The invariant that
 *      buys us: the committed state has advanced ⟺ the next turn's message
 *      exists. There is no "committed but not dispatched" window for a
 *      crash to fall into.
 *   2. A delivered turn is therefore judged by state alone: it executes
 *      when the state says its turn is still owed, and is dropped as stale
 *      otherwise. A duplicate delivery (redelivery after a worker died
 *      post-commit, a manual requeue racing a live carrier) cannot fork
 *      the run or spawn phantom messages.
 *   3. Turns additionally take an EXECUTION CLAIM on the run row — one
 *      atomic UPDATE (the claim predicate is the mutex, lock_version the
 *      ownership token). The decision state is re-read under the claim, so
 *      two carriers of the same turn cannot execute it concurrently; the
 *      loser drops. A claim abandoned by a dead worker is taken over after
 *      CLAIM_STALE_SECONDS (below the lanes' redeliver_timeout).
 *
 * For a message lost in ways the transport cannot see (queue table purged,
 * database restored from a backup), app:run:requeue re-derives the owed
 * turn from the same state and dispatches it — safe at any time, because
 * (2) makes an extra delivery a no-op.
 */
final class RunEngine
{
    /**
     * How long an execution claim is honored before another worker may take
     * it over (a dead worker must not wedge a run forever). Bounds the
     * worst-case turn duration by construction: keep it comfortably above
     * any legitimate single turn (one LLM request ≤ TASKLOOM_LLM_TIMEOUT;
     * one tool turn ≤ its calls' timeouts × attempts) and comfortably below
     * the transport lanes' redeliver_timeout (7200s), so a redelivered
     * message can always take over a claim its dead owner abandoned.
     */
    private const int CLAIM_STALE_SECONDS = 3600;

    /**
     * @param array<string, int> $budgets step_budget, tool_retries, circuit_breaker
     */
    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly PromptCompiler $prompts,
        private readonly ToolboxResolver $resolver,
        private readonly ToolExecutorInterface $executor,
        private readonly ContextWindow $context,
        private readonly RunRepository $runs,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly RunGraph $graph,
        private readonly array $budgets = [],
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    /**
     * Run a task to completion, synchronously, driving the turn cores
     * inline. The command path behind app:run:now (without --queue).
     *
     * A stepped task is the same command over a graph (SPEC §13.3): the
     * parent run is created and its children (steps, then the final
     * consumer) are driven one after another through the same turn cores —
     * sequentially here, because this path is one process by definition.
     * Zero-step tasks never touch the graph path.
     */
    public function run(Task $task): Run
    {
        $graph = $this->graphFor($task);
        if (null !== $graph) {
            return $this->runGraph($task, $graph);
        }

        return $this->runOneTask($task);
    }

    /**
     * The zero-step path, byte-identical to v1 (SPEC §13.1): one run, the
     * task's own brief/toolbox, the turn loop driven inline.
     */
    private function runOneTask(Task $task): Run
    {
        $run = $this->begin($task);
        $state = $this->stateFor($run);

        try {
            while (true) {
                $result = $this->performLlmTurn($run, $state, $state->step + 1, async: false);
                if (RunTurnResult::AwaitToolTurn !== $result) {
                    break;
                }

                $result = $this->performToolTurn($run, $state, async: false);
                if (RunTurnResult::AwaitLlmTurn !== $result) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Unclassified escape (the turn cores classify their own
            // failures): fail the run loudly rather than leave it running.
            $run->markFailed(ErrorClass::Unknown);
            $this->appendEvent($run, RunEventType::Failure, ['reason' => $e->getMessage()], errorClass: ErrorClass::Unknown);
        }

        $this->runs->save($run);

        return $run;
    }

    /**
     * The synchronous graph driver (SPEC §13.3): create the parent and its
     * first children, then repeatedly take the next executable child and
     * drive it through the same turn cores the standalone path uses. Each
     * child's terminal commit derives and applies the graph's next step.
     */
    private function runGraph(Task $task, RunGraph $graph): Run
    {
        $parent = $graph->beginGraph($task, async: false);

        while (null !== ($child = $graph->nextExecutable($parent))) {
            $this->driveChild($child, $graph);
        }

        $this->em->refresh($parent);

        return $parent;
    }

    /**
     * Drive one child run to a terminal state, inline: the turn loop, then
     * the graph's advancement hook — the same commit boundary as the async
     * path, so a mid-DAG failure settles the parent before the driver asks
     * for the next child.
     */
    private function driveChild(Run $child, RunGraph $graph): void
    {
        $state = $this->stateFor($child);

        try {
            while (true) {
                $result = $this->performLlmTurn($child, $state, $state->step + 1, async: false);
                if (RunTurnResult::AwaitToolTurn !== $result) {
                    break;
                }

                $result = $this->performToolTurn($child, $state, async: false);
                if (RunTurnResult::AwaitLlmTurn !== $result) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // The child is terminal in-memory when the failure happened in
            // the terminal commit itself (rolled back, so the DB is
            // consistent): fail loudly — a retry/re-run is the honest
            // remedy for an infrastructure error. Otherwise the escape is
            // unclassified inside a turn core: fail the child loudly and
            // let commitTerminal settle the graph with it (SPEC §13.5).
            if ($child->isTerminal()) {
                throw $e;
            }

            $this->commitTerminal($child, false, function () use ($child, $e): void {
                $child->markFailed(ErrorClass::Unknown);
                $this->appendEvent($child, RunEventType::Failure, ['reason' => $e->getMessage()], errorClass: ErrorClass::Unknown);
                $this->em->flush();
            });
        }

        if (!$child->isTerminal()) {
            // Every turn core commits a terminal state on its Done path;
            // reaching here would mean a turn core returned Done without one.
            throw new \LogicException(\sprintf('Child run %d left the turn loop without a terminal state.', $child->getId()));
        }
    }

    /**
     * The graph, when this task has steps (SPEC §13.1: zero-step tasks
     * never come here).
     */
    private function graphFor(Task $task): ?RunGraph
    {
        return $this->graph->hasSteps($task) ? $this->graph : null;
    }

    /**
     * Create a run for the async engine: frozen toolbox snapshot, initial
     * checkpoint (budgets + compiled prompt head), status `queued`. The
     * caller dispatches the first LlmTurnMessage (see nextTurnMessage()); a
     * queued run is a task waiting for the `llm` lane — the persisted,
     * FIFO-ordered wait that survives restarts (SPEC §6).
     *
     * A stepped task starts as a run GRAPH (SPEC §13.3): beginGraph() creates
     * the parent and dispatches the root steps' messages inside its creation
     * transaction — this call IS the dispatch, so the command does not look
     * for a first turn message on a parent (parents execute no turns).
     */
    public function start(Task $task): Run
    {
        $graph = $this->graphFor($task);
        if (null !== $graph) {
            return $graph->beginGraph($task, async: true);
        }

        return $this->begin($task);
    }

    /**
     * The message that advances this run, or null when it is terminal and
     * needs nothing — derived from committed state only. Used by
     * app:run:now --queue for the first turn and by app:run:requeue for
     * recovery after a message was lost.
     */
    public function nextTurnMessage(Run $run): LlmTurnMessage|ToolTurnMessage|null
    {
        if (!\in_array($run->getStatus(), [RunStatus::Queued, RunStatus::Running], true)) {
            return null;
        }

        // A parent run executes no turns of its own (SPEC §13.3).
        if (!$run->executesTurns()) {
            return null;
        }

        // A queued child whose graph already settled was orphaned by a
        // sibling's failure: it owes nothing (SPEC §13.5).
        if ($this->graph->isSkipped($run)) {
            return null;
        }

        $state = $this->stateFor($run);

        if (null !== $state->pendingToolTurn) {
            return new ToolTurnMessage((int) $run->getId(), $state->pendingToolTurn->step);
        }

        return new LlmTurnMessage((int) $run->getId(), $state->step + 1);
    }

    /**
     * One LLM turn of a run, from a queue delivery: takes the claim, judges
     * the delivery against committed state under the claim, and executes
     * only when this step's LLM turn is still owed. Everything else — a
     * duplicate, a late redelivery, a terminal run — is dropped as stale.
     *
     * When the turn requests tools, the pending tool turn and the tool
     * lane's message commit together; when it completes the run, nothing
     * follows.
     */
    public function llmTurn(int $runId, int $step): RunTurnResult
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof Run) {
            $this->logger->debug('Dropping LlmTurnMessage: run {run} no longer exists.', ['run' => $runId]);

            return RunTurnResult::Stale;
        }

        // No pre-claim checks: status and step are judged under the claim,
        // on state as committed — not on a snapshot that may already be
        // stale by the time the claim is won.
        $token = $this->claim($run);
        if (null === $token) {
            $this->logger->debug('Run {run}: LlmTurnMessage step {step} lost the claim race; dropping.', ['run' => $runId, 'step' => $step]);

            return RunTurnResult::Stale;
        }

        try {
            if (!$this->refresh($run)) {
                return RunTurnResult::Stale;
            }

            if (!\in_array($run->getStatus(), [RunStatus::Queued, RunStatus::Running], true)) {
                $this->logger->debug('Run {run}: dropping LlmTurnMessage (status {status} under claim).', ['run' => $runId, 'status' => $run->getStatus()->value]);

                return RunTurnResult::Stale;
            }

            // A parent aggregator executes no turns (SPEC §13.3): a message
            // for one is stale by construction.
            if (!$run->executesTurns()) {
                $this->logger->debug('Run {run}: dropping LlmTurnMessage (parent runs execute no turns).', ['run' => $runId]);

                return RunTurnResult::Stale;
            }

            // A queued child of a settled graph was orphaned by a sibling's
            // failure: it never started and must never start (SPEC §13.5).
            if ($this->graph->isSkipped($run)) {
                $this->logger->debug('Run {run}: dropping LlmTurnMessage (its graph settled before this child started).', ['run' => $runId]);

                return RunTurnResult::Stale;
            }

            $state = $this->stateFor($run);

            if (null !== $state->pendingToolTurn) {
                // The LLM turn already committed — and with it the tool
                // turn's message. Nothing is owed by this delivery.
                return RunTurnResult::Stale;
            }

            if ($step !== $state->step + 1) {
                $this->logger->debug('Run {run}: dropping LlmTurnMessage step {step} (run step {current}).', ['run' => $runId, 'step' => $step, 'current' => $state->step]);

                return RunTurnResult::Stale;
            }

            return $this->performLlmTurn($run, $state, $step, async: true, claimToken: $token);
        } finally {
            $this->release($run, $token);
        }
    }

    /**
     * One tool turn of a run, from a queue delivery: executes the calls the
     * model requested (resuming at the persisted position if a previous
     * worker died mid-turn) and commits the exchange together with the next
     * LLM turn's message. A delivery with no pending turn is stale — the
     * pending checkpoint and the lane message are committed atomically, so
     * "no pending turn" means a successor already exists (or the run moved
     * past it / ended).
     */
    public function toolTurn(int $runId, int $step): RunTurnResult
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof Run) {
            $this->logger->debug('Dropping ToolTurnMessage: run {run} no longer exists.', ['run' => $runId]);

            return RunTurnResult::Stale;
        }

        $token = $this->claim($run);
        if (null === $token) {
            $this->logger->debug('Run {run}: ToolTurnMessage step {step} lost the claim race; dropping.', ['run' => $runId, 'step' => $step]);

            return RunTurnResult::Stale;
        }

        try {
            if (!$this->refresh($run)) {
                return RunTurnResult::Stale;
            }

            if (RunStatus::Running !== $run->getStatus()) {
                $this->logger->debug('Run {run}: dropping ToolTurnMessage (status {status} under claim).', ['run' => $runId, 'status' => $run->getStatus()->value]);

                return RunTurnResult::Stale;
            }

            $state = $this->stateFor($run);

            if (null === $state->pendingToolTurn || $state->pendingToolTurn->step !== $step) {
                $this->logger->debug('Run {run}: dropping ToolTurnMessage step {step} (nothing pending for it).', ['run' => $runId, 'step' => $step]);

                return RunTurnResult::Stale;
            }

            return $this->performToolTurn($run, $state, async: true, claimToken: $token);
        } finally {
            $this->release($run, $token);
        }
    }

    /**
     * Create the run with its frozen constitution (SPEC §4.1): resolved
     * toolbox snapshot (enriched, so no turn ever needs the catalog) and
     * the initial checkpoint — budgets, the compiled prompt head, empty
     * window. Saved as `queued`; it becomes `running` when its first LLM
     * turn actually starts.
     */
    private function begin(Task $task): Run
    {
        $tools = $this->resolver->resolve($task);

        $run = new Run($task);
        $run->setToolboxSnapshot(ToolboxSnapshot::fromTools($tools));

        $state = new LoopState(
            stepBudget: $this->budgets['step_budget'] ?? 50,
            toolRetries: $this->budgets['tool_retries'] ?? 2,
            circuitBreakerThreshold: $this->budgets['circuit_breaker'] ?? 3,
            promptHead: $this->prompts->compile($task, $tools),
        );
        $run->setCheckpoint($state->toCheckpoint());

        $this->runs->save($run);

        return $run;
    }

    /**
     * One LLM request (SPEC §5.4): request event → the wire → response
     * event → either terminal (completion / malformed) or a pending tool
     * turn whose lane message is committed with the checkpoint. In async
     * mode the commit and the tool lane's enqueue are one transaction: the
     * response, the pending state, and the successor message live or die
     * together.
     */
    private function performLlmTurn(Run $run, LoopState $state, int $step, bool $async, ?int $claimToken = null): RunTurnResult
    {
        if ($step > $state->stepBudget) {
            $this->commitTerminal($run, $async, function () use ($run, $state): void {
                $run->markIncomplete();
                $this->appendEvent(
                    $run,
                    RunEventType::Failure,
                    ['reason' => \sprintf('step budget exhausted: %d exchanges, no completion declaration', $state->stepBudget)],
                    errorClass: ErrorClass::BudgetExceeded,
                );
                $this->em->flush();
            });
            $this->logger->warning('Run {run}: step budget exhausted.', ['run' => $run->getId()]);

            return RunTurnResult::Done;
        }

        if (RunStatus::Queued === $run->getStatus()) {
            // First turn of a queued run: the wait is over, the wire slot
            // has been entered. Flushed with the request event below.
            $run->markStarted();
        }

        $state->step = $step;
        $run->incrementStepCount(); // requests issued — counts a crash-redelivered request honestly

        $this->appendEvent($run, RunEventType::LlmRequest, [
            'step' => $step,
            'exchangesSoFar' => \count($state->exchanges),
        ]);
        $this->em->flush();

        // The toolbox comes from the run's snapshot, never the catalog: the
        // run's constitution does not move (SPEC §4.1), even mid-run, even
        // if the tool was renamed, re-schematized, or deleted in between.
        $tools = ToolboxSnapshot::toTools($run->getToolboxSnapshot());
        $state->promptHead ??= $this->prompts->compile($run->getTask(), $tools);

        try {
            $messages = $this->context->buildMessages($state->promptHead, $state->exchanges);
            $response = $this->llm->chat($messages, $this->prompts->toolsToOpenAi($tools));
        } catch (ContextExhaustedException $e) {
            return $this->failRun($run, $e->errorClass, $e->getMessage(), $async);
        } catch (LlmRequestException $e) {
            return $this->failRun($run, $e->errorClass, $e->getMessage(), $async);
        }

        $responsePayload = [
            'step' => $step,
            'finishReason' => $response->finishReason,
            'content' => $response->content,
            'reasoningContent' => $response->reasoningContent,
            'usage' => $response->usage,
        ];

        if (!$response->wantsToolCall()) {
            // Terminal message: completion must BE the result (SPEC §5.4).
            // Response event and terminal status commit together — and, for
            // a step child, the graph's advancement commits with them.
            $this->commitTerminal($run, $async, function () use ($run, $response, $responsePayload): void {
                $this->appendEvent($run, RunEventType::LlmResponse, $responsePayload, durationMs: $response->durationMs);

                if (null !== $response->content && '' !== trim($response->content)) {
                    $run->markSucceeded();
                    $this->appendEvent($run, RunEventType::Completion, ['result' => $response->content]);
                } else {
                    $run->markIncomplete();
                    $this->appendEvent(
                        $run,
                        RunEventType::Failure,
                        ['reason' => 'terminal message with no content — no completion declaration'],
                        errorClass: ErrorClass::LlmMalformedResponse,
                    );
                }
                $this->em->flush();
            });

            return RunTurnResult::Done;
        }

        // Tool calls: the work item is persisted — and, when async, the
        // tool lane's message enqueued — in one commit. A worker that dies
        // anywhere before this commit leaves no trace and the redelivered
        // LLM turn re-executes; after it, the tool turn has a carrier.
        $this->commitTurn(function () use ($run, $state, $step, $response, $responsePayload): void {
            $this->appendEvent($run, RunEventType::LlmResponse, $responsePayload, durationMs: $response->durationMs);
            $state->pendingToolTurn = new PendingToolTurn(
                step: $step,
                assistantContent: $response->content,
                calls: $response->getToolCalls(),
            );
            $run->setCheckpoint($state->toCheckpoint());
            $this->em->flush();
        }, $async ? new ToolTurnMessage((int) $run->getId(), $step) : null, $async ? $run : null, $async ? $claimToken : null);

        return RunTurnResult::AwaitToolTurn;
    }

    /**
     * One tool turn: run every pending call in order, each a validate →
     * dispatch → retry-with-feedback cycle (SPEC §5.1, §5.2). Every
     * completed call is a durable point (result + resume position written
     * back first), so a crash re-runs at most the one call that was in
     * flight. Completion appends the exchange, checkpoints — and, when
     * async, enqueues the next LLM turn — in one commit.
     */
    private function performToolTurn(Run $run, LoopState $state, bool $async, ?int $claimToken = null): RunTurnResult
    {
        $pending = $state->pendingToolTurn;
        if (null === $pending) {
            return RunTurnResult::Done; // defensive; callers only invoke with a pending turn
        }

        $toolMap = [];
        foreach (ToolboxSnapshot::toTools($run->getToolboxSnapshot()) as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        try {
            while ($pending->nextIndex < \count($pending->calls)) {
                $call = $pending->calls[$pending->nextIndex];
                $pending->results[] = $this->executeToolCall($run, $toolMap, $state, $call, $async);
                ++$pending->nextIndex;

                // Durable point: the resume position moves with every
                // completed call. Worst case after a crash is one
                // in-flight call re-executed — not the whole turn.
                $run->setCheckpoint($state->toCheckpoint());
                $this->em->flush();
            }
        } catch (RunTerminatedException) {
            // Circuit breaker tripped: the run is needs_attention and the
            // chain deliberately stops here (no next turn is dispatched).
            return RunTurnResult::Done;
        }

        // The checkpoint event says an exchange is complete; the exchange
        // itself and the next LLM turn's message commit together.
        $nextStep = $state->step + 1;
        $this->commitTurn(function () use ($run, $state, $pending): void {
            $state->exchanges[] = [
                'assistant' => [
                    'content' => $pending->assistantContent,
                    'toolCalls' => $pending->calls,
                ],
                'toolResults' => $pending->results,
            ];
            $state->pendingToolTurn = null;

            // Checkpoint: every exchange persisted before the next request (§5.5).
            $run->setCheckpoint($state->toCheckpoint());
            $this->appendEvent($run, RunEventType::Checkpoint, ['step' => $state->step]);
            $this->em->flush();
        }, $async ? new LlmTurnMessage((int) $run->getId(), $nextStep) : null);

        return RunTurnResult::AwaitLlmTurn;
    }

    /**
     * Execute one tool call: validate → dispatch → result, with retries
     * feeding errors back to the model (§5.1, §5.2).
     *
     * @param array<string, Tool>                                              $toolMap
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     *
     * @return array{toolCallId: string, content: string}
     */
    private function executeToolCall(Run $run, array $toolMap, LoopState $state, array $call, bool $async): array
    {
        $callId = $call['id'];
        $toolName = $call['name'];
        $arguments = $call['arguments'];

        $tool = $toolMap[$toolName] ?? null;

        if (null === $tool) {
            // Prompt-injection mitigation by construction: a tool outside
            // the frozen toolbox never dispatches (SPEC §2.1, §4.1).
            $this->appendEvent(
                $run,
                RunEventType::ToolValidationError,
                ['tool' => $toolName, 'detail' => 'tool not in this run\'s toolbox'],
                errorClass: ErrorClass::ToolNotFound,
            );

            return [
                'toolCallId' => $callId,
                'content' => $this->errorFeedbackJson('tool_not_found', $toolName, 'not in this run\'s toolbox'),
            ];
        }

        $attempt = 1;

        while (true) {
            $errors = $this->executor->validate($tool, $arguments);

            if ([] !== $errors) {
                $this->appendEvent(
                    $run,
                    RunEventType::ToolValidationError,
                    ['tool' => $toolName, 'detail' => implode('; ', $errors), 'attempt' => $attempt],
                    errorClass: ErrorClass::InvalidArguments,
                    attemptNo: $attempt,
                );
                $this->em->flush();

                return [
                    'toolCallId' => $callId,
                    'content' => $this->errorFeedbackJson('invalid_arguments', $toolName, implode('; ', $errors), $attempt),
                ];
            }

            $this->appendEvent(
                $run,
                RunEventType::ToolCall,
                ['tool' => $toolName, 'arguments' => $arguments, 'attempt' => $attempt],
                attemptNo: $attempt,
            );

            try {
                $payload = $this->executor->execute($tool, $arguments);
            } catch (ToolExecutionException $e) {
                $this->appendEvent(
                    $run,
                    RunEventType::ToolResult,
                    ['tool' => $toolName, 'detail' => $e->getMessage(), 'attempt' => $attempt],
                    errorClass: $e->errorClass,
                    attemptNo: $attempt,
                );
                $this->em->flush();

                // Circuit breaker: same tool + same error class, N times
                // (§5.2) → needs_attention. The run STOPS — feeding the
                // breaker back to the model would just loop it.
                if ($this->bumpFailureCount($state, $toolName, $e->errorClass) >= $state->circuitBreakerThreshold) {
                    $this->tripCircuitBreaker($run, $toolName, $e->errorClass, $async);
                    $this->em->flush();

                    throw new RunTerminatedException(\sprintf('circuit breaker tripped on tool "%s" (%s)', $toolName, $e->errorClass->value), $e->errorClass);
                }

                if ($attempt <= $state->toolRetries) {
                    $this->appendEvent(
                        $run,
                        RunEventType::ToolRetry,
                        ['tool' => $toolName, 'attempt' => $attempt],
                        errorClass: $e->errorClass,
                        attemptNo: $attempt,
                    );
                    $this->em->flush();
                    ++$attempt;

                    continue;
                }

                return [
                    'toolCallId' => $callId,
                    'content' => $this->errorFeedbackJson('tool_error', $toolName, $e->getMessage(), $attempt),
                ];
            }

            $cappedContent = $this->context->capToolResult($payload['content'] ?? '');

            $this->appendEvent(
                $run,
                RunEventType::ToolResult,
                ['tool' => $toolName, 'content' => $cappedContent, 'isError' => false],
                attemptNo: $attempt,
                durationMs: (int) ($payload['durationMs'] ?? 0),
            );
            $this->em->flush();

            // Success clears the tool's circuit-breaker streak.
            unset($state->failureCounts[$this->failureKey($toolName, ErrorClass::ServerError)]);

            return ['toolCallId' => $callId, 'content' => $cappedContent];
        }
    }

    /**
     * The single commit point of a turn: writes the final state, and — when
     * a next message is given — enqueues it in the SAME transaction. The
     * transport shares this engine's Doctrine connection (doctrine://default),
     * so the INSERT joins the flush: committed state and successor message
     * are one atomic step (§6), and the claim release rides along with them
     * (no window where the state is committed but the claim is still held —
     * a successor delivered in that window would be dropped against a dead
     * owner's claim). Without a next message (sync mode, terminal outcomes)
     * this is a plain flush of $finalize.
     *
     * @param callable(): void $finalize     in-memory state changes + em flush
     * @param Run|null         $releaseRun   run whose claim to clear
     * @param int|null         $releaseToken claim ownership token
     */
    private function commitTurn(callable $finalize, ?object $nextTurn, ?Run $releaseRun = null, ?int $releaseToken = null): void
    {
        if (null === $nextTurn) {
            $finalize();

            return;
        }

        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $finalize();
            $this->enqueue($nextTurn);

            // The claim clears in the same commit: successor exists ⟺
            // owner has let go. (The finally-release in the turn entry
            // points is then an idempotent no-op on this path.)
            if (null !== $releaseRun && null !== $releaseToken) {
                $this->release($releaseRun, $releaseToken);
            }

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->em->clear(); // torn entities must not leak into the next turn
            }

            throw $e;
        }
    }

    /**
     * Enqueue the successor turn. Only reached in async mode — the MessageBus
     * is a constructor dependency precisely so the enqueue is available
     * inside commitTurn()'s transaction.
     */
    private function enqueue(object $message): void
    {
        $bus = $this->bus ?? throw new \LogicException('RunEngine has no message bus configured — async turn dispatch requires it.');

        $bus->dispatch($message);
    }

    /**
     * The terminal-commit choke point: a run's terminal state and its
     * graph's advancement commit in ONE transaction (SPEC §13.3). When a
     * step child settles, the settled child, the newly dispatched siblings
     * (rows + their lane messages), and the parent's settlement live or die
     * together — "the same transaction that commits that state" is the
     * advancement contract, not a best effort.
     *
     * For a standalone run (no parent) nothing else happens; the graph call
     * is a no-op and the commit is a plain flush. Unlike commitTurn() the
     * claim does not need to clear inside this transaction — a terminal run
     * has no successor, so there is no successor-delivery window to protect;
     * the turn entry points' finally-release remains the claimant's exit.
     */
    private function commitTerminal(Run $run, bool $async, callable $finalize): void
    {
        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $finalize();

            $this->graph->onTerminal($run, $async);

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->em->clear(); // torn entities must not leak into the next turn
            }

            throw $e;
        }
    }

    /**
     * Rebuild the loop state for a run: the checkpoint first (the run's own
     * history wins — including its budgets), configured values only as
     * fallback for runs that predate checkpointed budgets.
     */
    private function stateFor(Run $run): LoopState
    {
        return LoopState::fromCheckpoint(
            $run->getCheckpoint(),
            $this->budgets['step_budget'] ?? 50,
            $this->budgets['tool_retries'] ?? 2,
            $this->budgets['circuit_breaker'] ?? 3,
        );
    }

    /**
     * Re-read the run under the claim: the execute / drop decision must be
     * made on the state as committed, not on what was loaded before the
     * claim was won — another worker may have advanced the run in between
     * (that duplicate-delivery race is exactly what the claim adjudicates).
     * Also re-checks existence: the row may have been deleted concurrently.
     *
     * @return bool false when the run no longer exists
     */
    private function refresh(Run $run): bool
    {
        try {
            $this->em->refresh($run);

            return true;
        } catch (EntityNotFoundException) {
            return false;
        }
    }

    /**
     * Take the execution claim on the run row for one turn: one atomic
     * UPDATE whose WHERE clause IS the mutex — it succeeds only when no
     * live claim is held (a claim abandoned for CLAIM_STALE_SECONDS may be
     * taken over). lock_version is the ownership token handed back here;
     * release() clears the claim only while the token still matches, so a
     * worker whose claim was taken over cannot clear its successor's.
     *
     * Written via raw SQL so entity flushes cannot interact with it: the
     * entity only ever reads lock_version/claimed_at, and Doctrine writes
     * changed fields only, so an ORM flush can never clobber them. The
     * token is read back after the UPDATE; until the fresh claimed_at goes
     * stale (an hour), nobody else may alter either column, so the read is
     * race-free by construction.
     *
     * @return int|null the claim token, or null when another worker holds a
     *                  live claim (the delivery must drop)
     */
    private function claim(Run $run): ?int
    {
        $id = $run->getId();
        if (null === $id) {
            return null;
        }

        $now = time();

        $updated = $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :now WHERE id = :id AND (claimed_at IS NULL OR claimed_at <= :staleBefore)',
            [
                'now' => $now,
                'id' => $id,
                'staleBefore' => $now - self::CLAIM_STALE_SECONDS,
            ],
        );

        if (1 !== $updated) {
            return null;
        }

        $token = $this->em->getConnection()->fetchOne('SELECT lock_version FROM run WHERE id = :id', ['id' => $id]);

        return \is_numeric($token) ? (int) $token : null;
    }

    /**
     * Release the claim — only if it is still ours (a takeover by another
     * worker incremented the version and must not be cleared).
     */
    private function release(Run $run, int $token): void
    {
        $id = $run->getId();
        if (null === $id) {
            return;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE run SET claimed_at = NULL WHERE id = :id AND lock_version = :token',
            ['id' => $id, 'token' => $token],
        );
    }

    /**
     * Terminal classified failure (LLM/context), shared by the turn cores.
     * The terminal state commits through commitTerminal(), so a step child's
     * graph advances with the failure (SPEC §13.5).
     */
    private function failRun(Run $run, ErrorClass $errorClass, string $reason, bool $async = false): RunTurnResult
    {
        $this->commitTerminal($run, $async, function () use ($run, $errorClass, $reason): void {
            $run->markFailed($errorClass);
            $this->appendEvent($run, RunEventType::Failure, ['reason' => $reason], errorClass: $errorClass);
            $this->em->flush();
        });

        return RunTurnResult::Done;
    }

    private function tripCircuitBreaker(Run $run, string $toolName, ErrorClass $errorClass, bool $async = false): void
    {
        $this->commitTerminal($run, $async, function () use ($run, $toolName, $errorClass): void {
            $run->markNeedsAttention($errorClass);
            $this->appendEvent(
                $run,
                RunEventType::CircuitBreaker,
                ['tool' => $toolName, 'errorClass' => $errorClass->value],
                errorClass: $errorClass,
            );
            $this->em->flush();
        });
        $this->logger->error('Run {run}: circuit breaker tripped on {tool}.', [
            'run' => $run->getId(),
            'tool' => $toolName,
        ]);
    }

    private function bumpFailureCount(LoopState $state, string $toolName, ErrorClass $errorClass): int
    {
        $key = $this->failureKey($toolName, $errorClass);
        $state->failureCounts[$key] = ($state->failureCounts[$key] ?? 0) + 1;

        return $state->failureCounts[$key];
    }

    private function failureKey(string $toolName, ErrorClass $errorClass): string
    {
        return $toolName.'|'.$errorClass->value;
    }

    /**
     * Structured, actionable error fed back to the model so the loop
     * self-corrects (SPEC §5.1).
     *
     * @return string JSON
     */
    private function errorFeedbackJson(string $error, string $tool, string $detail, ?int $attempt = null): string
    {
        $payload = [
            'error' => $error,
            'tool' => $tool,
            'detail' => $detail,
        ];

        if (null !== $attempt) {
            $payload['attempt'] = $attempt;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendEvent(
        Run $run,
        RunEventType $type,
        array $payload,
        ?ErrorClass $errorClass = null,
        ?int $attemptNo = null,
        ?int $durationMs = null,
    ): RunEvent {
        $event = new RunEvent($type);
        $event->setPayload($payload);

        if (null !== $errorClass) {
            $event->setErrorClass($errorClass);
        }
        if (null !== $attemptNo) {
            $event->setAttemptNo($attemptNo);
        }
        if (null !== $durationMs) {
            $event->setDurationMs($durationMs);
        }

        $run->appendEvent($event);
        $this->em->persist($event);

        return $event;
    }
}
