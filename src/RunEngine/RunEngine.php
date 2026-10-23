<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Context\ContextExhaustedException;
use App\Context\ContextWindow;
use App\Context\ContextWindowResult;
use App\Context\ShedPlan;
use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\SessionDeclarationOutcome;
use App\Entity\StreamAuthor;
use App\Entity\StreamRole;
use App\Entity\Task;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolDefinition;
use App\Llm\LlmClientInterface;
use App\Llm\LlmRequestException;
use App\Llm\LlmResponse;
use App\Message\LlmTurnMessage;
use App\Message\SessionTurnMessage;
use App\Message\ToolTurnMessage;
use App\Repository\RunRepository;
use App\Session\SessionConstraintException;
use App\Session\SessionDeclareTool;
use App\Session\SessionMemoryRenderer;
use App\Session\SessionMemoryWriteException;
use App\Session\SessionSliceEngine;
use App\Session\SessionTool;
use App\Session\SessionToolRunner;
use App\Session\SessionUndeclared;
use App\Stream\StreamWindow;
use App\Stream\StreamWriter;
use App\Toolbox\ToolSchemaValidator;
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
 * Session slices (docs/design/SESSION_TASKS.md §8) ride this same loop — on
 * the `session` lane (§7): a slice is one run of a session-kind task;
 * `session_declare` settles it through the same terminal-commit choke
 * point step children use, and a `milestone` launches the next slice's run
 * and lane message inside the settlement commit itself.
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
     * How far back the transcript read reaches when compiling a request.
     *
     * A read guard, not a trim: the window keeps a bounded number of exchanges
     * (a trim that mattered the moment the budget was hit), so the newest few
     * hundred rows always contain everything it could keep. Without it, every
     * request would fetch the entire stream to throw most of it away — fine for
     * a run, ruinous for a being that never ends.
     */
    private const STREAM_ROW_WINDOW = 2000;

    private readonly StreamWriter $stream;

    /**
     * How long an execution claim is honored before another worker may take
     * it over (a dead worker must not wedge a run forever). Bounds the
     * worst-case turn duration by construction: keep it comfortably above
     * any legitimate single turn (one LLM request ≤ TASKLOOM_LLM_TIMEOUT;
     * one tool turn ≤ its calls' timeouts × attempts) and comfortably below
     * the transport lanes' redeliver_timeout (7200s), so a redelivered
     * message can always take over a claim its dead owner abandoned.
     *
     * Public: the run surface's scheduler view flags a claim older than
     * this window as stale with the same number (SPEC §8).
     */
    public const int CLAIM_STALE_SECONDS = 3600;

    /**
     * Why a round carrying `session_declare` beside other calls is refused
     * whole (docs/design/SESSION_TASKS.md §5): the settle path must have no
     * ambiguity about which calls ran, and nothing comes after the
     * declaration.
     */
    private const string DECLARATION_ALONE_DETAIL = 'session_declare must be alone in its round — no other calls beside it; nothing dispatches from a round that carries it with company. Re-issue it by itself.';

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
        private readonly ?SessionMemoryRenderer $sessionMemory = null,
        private readonly ?SessionToolRunner $sessionToolRunner = null,
        private readonly ?SessionSliceEngine $slices = null,
        ?StreamWriter $stream = null,
    ) {
        // Defaulted rather than required, because the writer needs only the
        // EntityManager this engine already holds: a caller that wires a run by
        // hand should not have to know the transcript exists. Leaving it out
        // used to mean "no transcript", which is not a state that can be
        // allowed — a turn with nowhere to land is a lost turn.
        $this->stream = $stream ?? new StreamWriter($em);
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
        if (TaskKind::Session === $task->getKind()) {
            // Sessions are lane-driven by design: their slices wait in the
            // persisted queue like everything else, and the synchronous
            // path would hold a whole session — and its next slice — on one
            // process (docs/design/SESSION_TASKS.md §7, §8).
            throw SessionConstraintException::syncNotAllowed();
        }

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
        if (TaskKind::Session === $task->getKind()) {
            // A session's slice is not the ordinary begin() shape: it is
            // gated on the session's derived state, frozen with the slice
            // budget, and launched by the slice engine
            // (docs/design/SESSION_TASKS.md §8). The launcher dispatches its
            // first turn from RunEngine::nextTurnMessage() as usual.
            $slices = $this->slices ?? throw new \LogicException('The run engine has no slice engine — a session cannot start. Wire App\\Session\\SessionSliceEngine in services.yaml.');

            return $slices->start($task);
        }

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
     *
     * The class picks the LANE (docs/design/SESSION_TASKS.md §7): a
     * session-kind run's LLM turns ride `session`, every other run's ride
     * `llm`; tool turns always ride `tools`. `LlmTurnMessage` never appears
     * on the `session` lane, which is what makes chat > task > session a
     * property of the configuration alone.
     */
    public function nextTurnMessage(Run $run): LlmTurnMessage|SessionTurnMessage|ToolTurnMessage|null
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

        return $this->llmTurnMessageFor($run, $state->step + 1);
    }

    /**
     * The LLM-turn message for a run, on the run's own lane: `session` for
     * a session-kind run (the slice engine's carrier too), `llm` for every
     * other run. The ONE constructor for both, so no path can put a
     * session's turn on the task lane or a task's turn on the session lane
     * (docs/design/SESSION_TASKS.md §7).
     */
    private function llmTurnMessageFor(Run $run, int $step): LlmTurnMessage|SessionTurnMessage
    {
        return TaskKind::Session === $run->getTask()->getKind()
            ? new SessionTurnMessage((int) $run->getId(), $step)
            : new LlmTurnMessage((int) $run->getId(), $step);
    }

    /**
     * One LLM turn of a run, from a queue delivery: takes the claim, judges
     * the delivery against committed state under the claim, and executes
     * only when this step's LLM turn is still owed. Everything else — a
     * duplicate, a late redelivery, a terminal run — is dropped as stale.
     *
     * A session-kind run's delivery rides the `session` lane
     * (`SessionTurnMessage`, docs/design/SESSION_TASKS.md §7): the same
     * core and the same checks — the lane is the only difference, and
     * `$delivery` is the short name of the delivered class so the log
     * lines name the message an operator is actually chasing.
     *
     * When the turn requests tools, the pending tool turn and the tool
     * lane's message commit together; when it completes the run, nothing
     * follows.
     */
    public function llmTurn(int $runId, int $step, string $delivery = 'LlmTurnMessage'): RunTurnResult
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof Run) {
            $this->logger->debug('Dropping {delivery}: run {run} no longer exists.', ['delivery' => $delivery, 'run' => $runId]);

            return RunTurnResult::Stale;
        }

        // No pre-claim checks: status and step are judged under the claim,
        // on state as committed — not on a snapshot that may already be
        // stale by the time the claim is won.
        $token = $this->claim($run);
        if (null === $token) {
            $this->logLostClaim($run, $runId, $step, $delivery);

            return RunTurnResult::Stale;
        }

        try {
            if (!$this->refresh($run)) {
                return RunTurnResult::Stale;
            }

            if (!\in_array($run->getStatus(), [RunStatus::Queued, RunStatus::Running], true)) {
                $this->logger->debug('Run {run}: dropping {delivery} (status {status} under claim).', ['delivery' => $delivery, 'run' => $runId, 'status' => $run->getStatus()->value]);

                return RunTurnResult::Stale;
            }

            // A parent aggregator executes no turns (SPEC §13.3): a message
            // for one is stale by construction.
            if (!$run->executesTurns()) {
                $this->logger->debug('Run {run}: dropping {delivery} (parent runs execute no turns).', ['delivery' => $delivery, 'run' => $runId]);

                return RunTurnResult::Stale;
            }

            // A queued child of a settled graph was orphaned by a sibling's
            // failure: it never started and must never start (SPEC §13.5).
            if ($this->graph->isSkipped($run)) {
                $this->logger->debug('Run {run}: dropping {delivery} (its graph settled before this child started).', ['delivery' => $delivery, 'run' => $runId]);

                return RunTurnResult::Stale;
            }

            $state = $this->stateFor($run);

            if (null !== $state->pendingToolTurn) {
                // The LLM turn already committed — and with it the tool
                // turn's message. Nothing is owed by this delivery.
                return RunTurnResult::Stale;
            }

            if ($step !== $state->step + 1) {
                $this->logger->debug('Run {run}: dropping {delivery} step {step} (run step {current}).', ['delivery' => $delivery, 'run' => $runId, 'step' => $step, 'current' => $state->step]);

                return RunTurnResult::Stale;
            }

            return $this->performLlmTurn($run, $state, $step, async: true, claimToken: $token);
        } finally {
            $this->release($run, $token);
        }
    }

    /**
     * A delivery that lost the claim race, at a level that matches what it
     * means.
     *
     * A *fresh* claim is the case worth shouting about, and it is the one this
     * bug hid behind: the boot sweep declined to clear a claim, the requeue
     * dispatched the turn it could see was owed, and the delivery bounced off a
     * lock nobody would release for another hour. Every line the operator got
     * said "requeued"; the run was stuck. So a fresh claim logs at warning,
     * which survives prod's fingers_crossed buffering (action_level: error
     * discards debug/info) instead of being swallowed.
     *
     * A claim past the staleness window is a different, benign story — a
     * duplicate delivery after a takeover — and stays at debug, because a
     * healthy system produces those.
     */
    private function logLostClaim(Run $run, int $runId, int $step, string $message): void
    {
        $claimedAt = $run->getClaimedAt();
        $age = null === $claimedAt ? null : time() - $claimedAt;
        $live = null !== $age && $age < self::CLAIM_STALE_SECONDS;

        if ($live) {
            $this->logger->warning('Run {run}: {message} step {step} lost the claim race to a LIVE claim (held {age}s by {holder}); dropping. If this run is not progressing, its claim is stuck and no delivery will be accepted until it is cleared.', [
                'run' => $runId,
                'message' => $message,
                'step' => $step,
                'age' => $age,
                // The label is the only thing that can say who to go and look
                // at; a claim taken before the label existed reads as null.
                'holder' => $run->getClaimFleet() ?? 'an unlabelled claimant',
            ]);

            return;
        }

        $this->logger->debug('Run {run}: {message} step {step} lost the claim race (claim stale); dropping.', [
            'run' => $runId,
            'message' => $message,
            'step' => $step,
        ]);
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
            $this->logLostClaim($run, $runId, $step, 'ToolTurnMessage');

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
     * The rendered `## Memories` block for a session run, or null for an
     * ordinary run (docs/design/SESSION_TASKS.md §4.1, build order step 2).
     *
     * Rebuilt from the store on EVERY request — that is the point of the
     * seam: the head is frozen, the memory is not, so an operator edit
     * lands on the session's next request rather than at a slice boundary.
     * Only a session-kind task gets a block; a run-kind task's store is
     * empty by construction (the store refuses writes for it), so the
     * branch is about attribution, not just cost — a block must never be
     * attributed to a run that is not a session.
     *
     * Note the placement: this is called on the LLM-turn path only. The
     * block is a request input, not checkpoint state, so nothing about it
     * is persisted — a crash and resume re-reads the store and rebuilds it.
     */
    private function sessionMemoryBlockFor(Run $run): ?string
    {
        if (null === $this->sessionMemory) {
            return null;
        }

        $task = $run->getTask();
        if (TaskKind::Session !== $task->getKind()) {
            return null;
        }

        return $this->sessionMemory->render($task);
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
        $run->setToolboxSnapshot(ToolboxSnapshot::fromDefinitions($tools));

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
            $sessionSlice = TaskKind::Session === $run->getTask()->getKind();

            $this->commitTerminal($run, $async, function () use ($run, $state, $sessionSlice): void {
                $run->markIncomplete();
                $this->appendEvent(
                    $run,
                    RunEventType::Failure,
                    ['reason' => $sessionSlice
                        ? \sprintf('slice budget exhausted: %d exchanges, no session_declare declaration — the session stops for a human (docs/design/SESSION_TASKS.md §8)', $state->stepBudget)
                        : \sprintf('step budget exhausted: %d exchanges, no completion declaration', $state->stepBudget)],
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
            'exchangesSoFar' => \count($this->streamExchanges()),
        ]);
        $this->em->flush();

        // The toolbox comes from the run's snapshot, never the catalog: the
        // run's constitution does not move (SPEC §4.1), even mid-run, even
        // if the tool was renamed, re-schematized, or deleted in between.
        $tools = ToolboxSnapshot::toDefinitions($run->getToolboxSnapshot());
        $state->promptHead ??= $this->prompts->compile($run->getTask(), $tools);

        // The tool definitions travel on the same request as the messages,
        // so the budget has to see both: the window is asked to fit the
        // conversation around them, not the conversation alone. (Nothing is
        // compiled twice — the descriptors are built once and handed to
        // both the budget and the client.)
        $toolDescriptors = $this->prompts->toolsToOpenAi($tools);

        try {
            $exchanges = $this->streamExchanges();
            $fit = $this->context->buildMessages($state->promptHead, $exchanges, $toolDescriptors, $this->sessionMemoryBlockFor($run), new ShedPlan($state->contextBase, $state->contextBatch));
            $this->advanceContextBase($state, $fit, \count($exchanges));
            $this->recordTrim($run, $state, $fit);
            $response = $this->llm->chat($fit->messages, $toolDescriptors);
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
        ] + $response->ledgerAdditions();

        if (!$response->wantsToolCall()) {
            // Terminal message: completion must BE the result (SPEC §5.4).
            // For a session slice the completion IS the declaration — what a
            // terminal message means instead is the session's `undeclared`
            // policy, frozen in the checkpoint (docs/design/
            // SESSION_ENDINGS.md §4): `stop` (the default, and the shape
            // every deployment had before the knob) settles the slice
            // WITHOUT a declaration and stops the session for a human —
            // loud in the ledger, no retry, and no Completion artifact that
            // would pretend the slice delivered anything; `continue`
            // settles a non-empty message as a continuation and launches
            // the next slice, bounded by a counted streak.
            //
            // Response event and terminal status commit together — and, for
            // a step child, the graph's advancement commits with them.
            $sessionSlice = TaskKind::Session === $run->getTask()->getKind();

            // Under `continue` the streak of consecutive undeclared
            // settlements bounds the loop (docs/design/SESSION_ENDINGS.md
            // §4): it is DERIVED from the committed rows here — a stored
            // counter would be a second source of truth about progress —
            // and read before the terminal commit opens, so the settlement
            // itself is unencumbered.
            $priorUndeclaredStreak = $sessionSlice && SessionUndeclared::Continue === $state->undeclared
                ? $this->undeclaredStreakFor($run, $state->maxUndeclared)
                : 0;

            $this->commitTerminal($run, $async, function () use ($run, $state, $response, $responsePayload, $sessionSlice, $priorUndeclaredStreak): void {
                // A generation a guard cut short still has the stop recorded
                // on its response event (the error class and the timing ride
                // there), so the run's own completion stays "what the model
                // said" while the reason it ends early remains readable.
                $this->appendEvent($run, RunEventType::LlmResponse, $responsePayload, errorClass: $response->stop?->errorClass(), durationMs: $response->durationMs);

                if ($sessionSlice) {
                    $this->settleUndeclaredSlice($run, $state, $response, $priorUndeclaredStreak);
                } elseif (null !== $response->content && '' !== trim($response->content)) {
                    // Partial output is output: a cut that captured a
                    // terminal message still completes the run with what was
                    // said — the stop on the response event is what makes the
                    // truncation visible rather than silent.
                    $run->markSucceeded();
                    $this->appendEvent($run, RunEventType::Completion, ['result' => $response->content]);
                } else {
                    $run->markIncomplete();
                    $stop = $response->stop;
                    $this->appendEvent(
                        $run,
                        RunEventType::Failure,
                        ['reason' => null !== $stop ? $stop->detail : 'terminal message with no content — no completion declaration'],
                        errorClass: $stop?->errorClass() ?? ErrorClass::LlmMalformedResponse,
                    );
                }
                $this->em->flush();
            });

            return RunTurnResult::Done;
        }

        // A local model sometimes asks for the same call several times in one
        // turn — the same tool with the same arguments. Dispatching every
        // repeat buys nothing (the first answer stands) and costs a duplicate
        // ledger row plus the wall-clock of a whole extra tool round-trip,
        // which on a local GPU is the scarce resource. Drop the repeats here,
        // at the boundary where the assistant message is frozen, so the
        // message and its tool results stay consistent for the next request.
        //
        // Only exact within-turn repeats are affected: the same call in a
        // LATER turn can be a legitimate poll for state that has changed, and
        // is left alone.
        [$calls, $droppedCalls] = ToolCallPrimitives::dropDuplicateCalls($response->getToolCalls());

        if ([] !== $droppedCalls) {
            $this->logger->info(
                'Run {run}: dropped {count} duplicate tool call(s) in step {step} — the model asked for the same (tool, arguments) more than once.',
                [
                    'run' => $run->getId(),
                    'step' => $step,
                    'count' => \count($droppedCalls),
                    'tools' => array_map(static fn (array $call): string => $call['name'], $droppedCalls),
                ],
            );
        }

        // The ledger records what the model actually asked for — every call,
        // including the ones just dropped — alongside what was dropped. The
        // dispatched set is `toolCalls` minus `droppedDuplicates`, which is
        // what the tool_call events below will show; keeping both makes
        // "did the model repeat itself, or did the harness double-fire?"
        // answerable from the record alone (SPEC §5.3).
        $responsePayload['toolCalls'] = $response->getToolCalls();
        $responsePayload['droppedDuplicates'] = $droppedCalls;

        // Tool calls: the work item is persisted — and, when async, the
        // tool lane's message enqueued — in one commit. A worker that dies
        // anywhere before this commit leaves no trace and the redelivered
        // LLM turn re-executes; after it, the tool turn has a carrier.
        $this->commitTurn(function () use ($run, $state, $step, $response, $responsePayload, $calls): void {
            $this->appendEvent($run, RunEventType::LlmResponse, $responsePayload, errorClass: $response->stop?->errorClass(), durationMs: $response->durationMs);
            $state->pendingToolTurn = new PendingToolTurn(
                step: $step,
                assistantContent: $response->content,
                calls: $calls,
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
        foreach (ToolboxSnapshot::toDefinitions($run->getToolboxSnapshot()) as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        // A declaration is alone in its round (§5): "the settle path must
        // have no ambiguity about which calls ran, and nothing comes after
        // the declaration." A round that carries session_declare beside
        // other calls is refused whole — nothing dispatches — with the
        // standard error feedback, so the model re-issues it cleanly.
        $refuseRound = false;
        foreach ($pending->calls as $call) {
            if (null !== $this->declarationTool($toolMap[$call['name']] ?? null)) {
                $refuseRound = \count($pending->calls) > 1;
                break;
            }
        }

        try {
            while ($pending->nextIndex < \count($pending->calls)) {
                $call = $pending->calls[$pending->nextIndex];
                $tool = $toolMap[$call['name']] ?? null;
                $declaration = $this->declarationTool($tool);

                if (null !== $declaration) {
                    if ($refuseRound) {
                        $pending->results[] = $this->refuseCall($run, $call, self::DECLARATION_ALONE_DETAIL);
                    } else {
                        $errors = ToolSchemaValidator::validate($declaration->getSchema(), $call['arguments']);
                        $outcome = $this->declaredOutcome($call['arguments']);
                        if ([] === $errors && null !== $outcome && $declaration->allows($outcome)) {
                            // The declaration is the completion: settle the
                            // slice — and, for a milestone, launch the next
                            // slice — in one terminal commit, and stop the
                            // loop; nothing comes after it.
                            return $this->settleSlice($run, $state, $call, $outcome, $async);
                        }

                        // For a well-formed call naming an outcome the session
                        // cannot make, prefer the endings-aware refusal over the
                        // schema validator's generic enum message — "done is not
                        // available; declare milestone instead" teaches, while
                        // "must be one of: milestone" reads as a typo. Any
                        // OTHER shape error (missing summary, blank, extras)
                        // stays the validator's, whose message is exact.
                        $detail = match (true) {
                            null === $outcome => [] !== $errors ? implode('; ', $errors) : 'outcome must be one of: '.$declaration->labelList(),
                            !$declaration->allows($outcome) => $declaration->describeUnavailable($outcome),
                            default => implode('; ', $errors),
                        };

                        $pending->results[] = $this->refuseCall($run, $call, $detail);
                    }
                } elseif ($refuseRound) {
                    $pending->results[] = $this->refuseCall($run, $call, self::DECLARATION_ALONE_DETAIL);
                } else {
                    $pending->results[] = $this->executeToolCall($run, $toolMap, $state, $call, $async);
                }

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
            // One append path for every writer (docs/design/THE_STREAM.md
            // §2.1). The rows are the transcript now; the checkpoint below no
            // longer carries it, so these writes are the only copy and they
            // land in the same transaction as the turn that produced them.
            //
            // Two rows, because the wire protocol's exchange is two messages:
            // the assistant turn announcing its calls, then the tool turn
            // answering them. Folding both onto one row would be a shorter
            // table and a longer lie — the reassembly in {@see StreamWindow}
            // reads a `tool` row as "these results answer the call above",
            // and an assistant row carrying results of its own has no such
            // reading (its own `tool_calls` and `toolResults` are two halves
            // of one exchange, which is a shape nothing downstream produces).
            $this->stream->append(
                StreamAuthor::Being,
                StreamRole::Assistant,
                $pending->assistantContent ?? '',
                $pending->calls,
                [],
                run: $run,
            );

            if ([] !== $pending->results) {
                $this->stream->append(
                    StreamAuthor::Being,
                    StreamRole::Tool,
                    '',
                    [],
                    $pending->results,
                    run: $run,
                );
            }

            $state->pendingToolTurn = null;

            // Checkpoint: every exchange persisted before the next request (§5.5).
            $run->setCheckpoint($state->toCheckpoint());
            $this->appendEvent($run, RunEventType::Checkpoint, ['step' => $state->step]);
            $this->em->flush();
        }, $async ? $this->llmTurnMessageFor($run, $nextStep) : null);

        return RunTurnResult::AwaitLlmTurn;
    }

    /**
     * A refusal the model is expected to fix — malformed declaration round,
     * a declaration beside company, a declaration missing its outcome.
     * Records the refusal on the ledger (the same shape a malformed MCP
     * call takes) and returns the standard error feedback, so the model
     * re-issues the call cleanly on its next turn.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     *
     * @return array{toolCallId: string, content: string}
     */
    private function refuseCall(Run $run, array $call, string $detail): array
    {
        $callId = $call['id'];
        $toolName = $call['name'];

        $this->appendEvent(
            $run,
            RunEventType::ToolValidationError,
            ['tool' => $toolName, 'detail' => $detail, 'origin' => 'harness', 'attempt' => 1, 'toolCallId' => $callId],
            errorClass: ErrorClass::InvalidArguments,
            attemptNo: 1,
        );
        $this->em->flush();

        return [
            'toolCallId' => $callId,
            'content' => ToolCallPrimitives::errorFeedbackJson('invalid_arguments', $toolName, $detail, 1),
        ];
    }

    /**
     * The declaration's outcome from its arguments, or null when the value
     * is not a vocabulary member (a schema the validator could not enforce,
     * or a hand-built call): the caller refuses with feedback rather than
     * guessing. Membership in the SESSION's endings is a separate gate —
     * the frozen tool's own ({@see SessionDeclareTool::allows()}).
     *
     * @param array<string, mixed> $arguments
     */
    private function declaredOutcome(array $arguments): ?SessionDeclarationOutcome
    {
        $outcome = $arguments['outcome'] ?? null;

        return \is_string($outcome) ? SessionDeclarationOutcome::tryFrom($outcome) : null;
    }

    /**
     * The declaration tool a call names, or null when the call is not the
     * declaration. Since the endings freeze (docs/design/SESSION_ENDINGS.md
     * §3) this is always the FILTERED {@see SessionDeclareTool} variant:
     * the snapshot's `session_declare` harness entry rebuilds as one,
     * whatever it was frozen as.
     */
    private function declarationTool(?ToolDefinition $tool): ?SessionDeclareTool
    {
        return $tool instanceof SessionDeclareTool ? $tool : null;
    }

    /**
     * The declaration IS the slice's completion (docs/design/SESSION_TASKS.md
     * §8): validate (done by the caller), record the declaration on the run
     * and in the ledger, settle the run — and, for a `milestone`, launch the
     * next slice — in ONE terminal commit. Nothing comes after the
     * declaration, so this returns Done directly and skips the ordinary
     * tool-turn continuation (no exchange append, no next LLM message).
     *
     * Settling shapes (§8):
     *
     *  - `milestone` / `done` — a justified completion: the summary is the
     *    slice's artifact (the Completion event), and the run succeeds.
     *    A milestone's next slice is created here, inside the same commit.
     *  - `blocked` — a designed stop, not a fault: needs_attention with a
     *    Failure event carrying "declared blocked: …" and NO error class
     *    (the attention queue shows it unclassified; nothing broke).
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     */
    private function settleSlice(Run $run, LoopState $state, array $call, SessionDeclarationOutcome $outcome, bool $async): RunTurnResult
    {
        $callId = $call['id'];
        $arguments = $call['arguments'];
        $summary = \is_string($arguments['summary'] ?? null) ? $arguments['summary'] : '';
        $toolName = SessionTool::Declare->getName();

        $this->commitTerminal($run, $async, function () use ($run, $state, $callId, $arguments, $outcome, $summary, $toolName): void {
            $this->appendEvent(
                $run,
                RunEventType::ToolCall,
                ['tool' => $toolName, 'arguments' => $arguments, 'origin' => 'harness', 'attempt' => 1, 'toolCallId' => $callId],
                attemptNo: 1,
            );
            $this->appendEvent(
                $run,
                RunEventType::ToolResult,
                [
                    'tool' => $toolName,
                    'content' => $this->context->capToolResult((string) json_encode(['declared' => $outcome->value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                    'isError' => false,
                    'origin' => 'harness',
                    'attempt' => 1,
                    'toolCallId' => $callId,
                ],
                attemptNo: 1,
            );

            $run->recordDeclaration($outcome, $summary);
            $state->pendingToolTurn = null;
            $run->setCheckpoint($state->toCheckpoint());

            if (SessionDeclarationOutcome::Blocked === $outcome) {
                $run->markNeedsAttention();
                $this->appendEvent(
                    $run,
                    RunEventType::Failure,
                    ['reason' => \sprintf('declared blocked: %s — the session waits for a human (docs/design/SESSION_TASKS.md §8)', $summary)],
                );
            } else {
                $run->markSucceeded();
                $this->appendEvent($run, RunEventType::Completion, ['result' => $summary]);
            }

            $this->em->flush();

            // The milestone's continuation (§8): "the next slice's run row
            // and its first lane message commit in the same transaction as
            // the settlement." This closure runs inside commitTerminal's
            // transaction, so the launch joins it — the settled slice, the
            // declaration, and the successor's carrier live or die together.
            if (SessionDeclarationOutcome::Milestone === $outcome) {
                $slices = $this->slices ?? throw new \LogicException('The run engine has no slice engine — a milestone cannot launch its next slice. Wire App\\Session\\SessionSliceEngine in services.yaml.');
                $slices->advance($run);
            }
        });

        return RunTurnResult::Done;
    }

    /**
     * Settle a slice whose terminal message carried NO declaration
     * (docs/design/SESSION_TASKS.md §8's *(none)* row, docs/design/
     * SESSION_ENDINGS.md §4). What that means is the policy the slice
     * froze in its checkpoint:
     *
     *  - `stop` (the built-in default, and the shape every deployment had
     *    before the knob): the session stops for a human — the slice
     *    settles `incomplete` with a loud ledger line, and nothing
     *    launches. No retry.
     *  - `continue`: a non-empty message is a legitimate settlement. The
     *    slice succeeds with the message as its Completion artifact, a
     *    typed `undeclared` marker records the pattern for the digest, and
     *    the next slice launches in this same commit — UNLESS the counted
     *    streak reaches `maxUndeclared`, which stops the session for a
     *    human just as loudly (the circuit breaker's shape: a count, not a
     *    judgment).
     *
     * An EMPTY message stops under either policy: a slice that ends saying
     * nothing is a stall, not a settlement. And a message the streaming
     * guards CUT SHORT stops under either policy too — a cut is the harness
     * stopping a generation that misbehaved, not the model choosing to end
     * its slice, and continuing past it would defeat the guard that fired;
     * the stop's class and detail ride the failure so the truncation is
     * visible.
     *
     * Runs inside the terminal commit closure; the caller flushes after.
     */
    private function settleUndeclaredSlice(Run $run, LoopState $state, LlmResponse $response, int $priorUndeclaredStreak): void
    {
        $content = $response->content;
        $hasContent = null !== $content && '' !== trim($content);

        // A generation the streaming guards cut short (looping, silent, past
        // the duration cap, a failed stream) is not a settlement: the model
        // did not choose to end its slice — the harness stopped it. It stops
        // the session for a human under EITHER policy, with the stop's own
        // class and detail on the failure so the truncation is visible
        // rather than silent; continuing past a cut would defeat the very
        // guard that fired. (A cut that captured nothing at all never
        // reaches here: the client throws, and the slice settles failed with
        // the named reason.)
        if (null !== $response->stop) {
            $run->markIncomplete();
            $this->appendEvent(
                $run,
                RunEventType::Failure,
                ['reason' => \sprintf('terminal message cut by a guard: %s — the slice declared nothing; the session stops for a human (docs/design/SESSION_ENDINGS.md §4)', $response->stop->detail)],
                errorClass: $response->stop->errorClass(),
            );

            return;
        }

        $continues = $hasContent
            && SessionUndeclared::Continue === $state->undeclared
            && $priorUndeclaredStreak + 1 < $state->maxUndeclared;

        if ($continues) {
            $run->markSucceeded();
            $this->appendEvent($run, RunEventType::Undeclared, ['streak' => $priorUndeclaredStreak + 1, 'max' => $state->maxUndeclared]);
            $this->appendEvent($run, RunEventType::Completion, ['result' => $content]);
            $this->em->flush();

            // The continuation's launch, exactly as a milestone's: the
            // settled slice and the successor's carrier live or die in
            // this one commit (§8's advancement contract).
            $slices = $this->slices ?? throw new \LogicException('The run engine has no slice engine — an undeclared continuation cannot launch its next slice. Wire App\\Session\\SessionSliceEngine in services.yaml.');
            $slices->advance($run);

            return;
        }

        $reason = match (true) {
            !$hasContent => 'terminal message with no session_declare and no content — a slice that ends saying nothing is a stall, not a settlement; the session stops for a human (docs/design/SESSION_ENDINGS.md §4)',
            SessionUndeclared::Continue === $state->undeclared => \sprintf('terminal message with no session_declare — %d consecutive undeclared settlements; the session stops for a human (docs/design/SESSION_ENDINGS.md §4)', $priorUndeclaredStreak + 1),
            default => 'terminal message with no session_declare — a slice settles by declaration; the session stops for a human (docs/design/SESSION_TASKS.md §8)',
        };

        $run->markIncomplete();
        $this->appendEvent($run, RunEventType::Failure, ['reason' => $reason]);
    }

    /**
     * The count of consecutive undeclared settlements directly preceding
     * this slice — the newest-first walk of its committed siblings that
     * stops at the first slice that declared or did not succeed
     * (docs/design/SESSION_ENDINGS.md §4). A succeeded slice with no
     * declaration IS an undeclared continuation; the walk is bounded
     * because the streak it feeds can never grow past its own ceiling
     * without a stop breaking the run.
     */
    private function undeclaredStreakFor(Run $run, int $limit): int
    {
        $streak = 0;

        foreach ($this->runs->findNewestForTask($run->getTask(), $limit + 1) as $candidate) {
            if ($candidate->getId() === $run->getId()) {
                continue; // the slice settling right now is not history yet
            }

            if (RunStatus::Succeeded !== $candidate->getStatus() || null !== $candidate->getDeclarationOutcome()) {
                break;
            }

            ++$streak;
        }

        return $streak;
    }

    /**
     * Execute one tool call: validate → dispatch → result, with retries
     * feeding errors back to the model (§5.1, §5.2).
     *
     * Two dispatch routes, told apart by the tool itself (SPEC §4.1's
     * harness-tool clause, docs/design/SESSION_TASKS.md §5): a catalog tool
     * goes over the wire through {@see ToolExecutorInterface}; a harness
     * session tool runs in-process through {@see SessionToolRunner} — never
     * a server, never a request. The validate → feedback discipline is
     * shared: the same schema checker, the same error-feedback shape, so a
     * malformed harness call and a malformed MCP call teach the model the
     * same lesson.
     *
     * @param array<string, ToolDefinition>                                    $toolMap
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

        if ($tool instanceof SessionDeclareTool) {
            // The declaration settles through the tool turn's settle path
            // and is intercepted there; reaching dispatch would be a
            // wiring bug.
            throw new \LogicException('session_declare settles the slice through the engine\'s settle path — it is never dispatched as an ordinary tool call.');
        }

        if ($tool instanceof SessionTool) {
            return $this->executeHarnessToolCall($run, $tool, $call);
        }

        if (!$tool instanceof Tool) {
            // Prompt-injection mitigation by construction: a tool outside
            // the frozen toolbox never dispatches (SPEC §2.1, §4.1).
            $this->appendEvent(
                $run,
                RunEventType::ToolValidationError,
                ['tool' => $toolName, 'detail' => 'tool not in this run\'s toolbox', 'toolCallId' => $callId],
                errorClass: ErrorClass::ToolNotFound,
            );

            return [
                'toolCallId' => $callId,
                'content' => ToolCallPrimitives::errorFeedbackJson('tool_not_found', $toolName, 'not in this run\'s toolbox'),
            ];
        }

        $attempt = 1;

        while (true) {
            $errors = $this->executor->validate($tool, $arguments);

            if ([] !== $errors) {
                $this->appendEvent(
                    $run,
                    RunEventType::ToolValidationError,
                    ['tool' => $toolName, 'detail' => implode('; ', $errors), 'attempt' => $attempt, 'toolCallId' => $callId],
                    errorClass: ErrorClass::InvalidArguments,
                    attemptNo: $attempt,
                );
                $this->em->flush();

                return [
                    'toolCallId' => $callId,
                    'content' => ToolCallPrimitives::errorFeedbackJson('invalid_arguments', $toolName, implode('; ', $errors), $attempt),
                ];
            }

            $this->appendEvent(
                $run,
                RunEventType::ToolCall,
                ['tool' => $toolName, 'arguments' => $arguments, 'attempt' => $attempt, 'toolCallId' => $callId],
                attemptNo: $attempt,
            );

            try {
                $payload = $this->executor->execute($tool, $arguments);
            } catch (ToolExecutionException $e) {
                $this->appendEvent(
                    $run,
                    RunEventType::ToolResult,
                    ['tool' => $toolName, 'detail' => $e->getMessage(), 'attempt' => $attempt, 'toolCallId' => $callId],
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
                    'content' => ToolCallPrimitives::errorFeedbackJson('tool_error', $toolName, $e->getMessage(), $attempt),
                ];
            }

            // Stored whole; the window caps it at render
            // ({@see \App\Context\ContextWindow::capToolResult}). The model sees
            // the capped view this turn either way, so nothing about the
            // request changes — what changes is that the ledger keeps the
            // result rather than a prefix of it.
            $content = (string) ($payload['content'] ?? '');

            $this->appendEvent(
                $run,
                RunEventType::ToolResult,
                ['tool' => $toolName, 'content' => $content, 'isError' => false, 'toolCallId' => $callId],
                attemptNo: $attempt,
                durationMs: (int) ($payload['durationMs'] ?? 0),
            );
            $this->em->flush();

            // Success clears the tool's circuit-breaker streak.
            unset($state->failureCounts[$this->failureKey($toolName, ErrorClass::ServerError)]);

            // What goes back to the model this turn is the same capped view it
            // would get from the window on the next one, so the live request and
            // the replayed one agree byte for byte — and agree again after a
            // restart, when the exchange is rebuilt from these events. Returning
            // the store's whole result here while rendering it capped later
            // would be a one-turn difference in the same conversation.
            return ['toolCallId' => $callId, 'content' => $this->context->capToolResult($content)];
        }
    }

    /**
     * Execute one harness session tool (SPEC §4.1's harness-tool clause,
     * docs/design/SESSION_TASKS.md §5): validate, run in-process, record —
     * the MCP path with the transport removed and the memory store
     * underneath.
     *
     * A refusal is the model's to fix, not the harness's to retry: a write
     * the store refuses (over the per-write cap, blank) comes back as
     * `invalid_arguments` feedback naming the knob, exactly as a schema
     * refusal does on the MCP side. The engine's tool-retry loop is not
     * engaged — the retry is the model's next turn, with the feedback in
     * hand.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     *
     * @return array{toolCallId: string, content: string}
     */
    private function executeHarnessToolCall(Run $run, SessionTool $tool, array $call): array
    {
        $callId = $call['id'];
        $toolName = $tool->getName();
        $arguments = $call['arguments'];

        if (null === $this->sessionToolRunner) {
            throw new \LogicException(\sprintf('The run engine has no session tool runner — harness call "%s" cannot dispatch. Wire App\\Session\\SessionToolRunner in services.yaml.', $toolName));
        }

        $errors = ToolSchemaValidator::validate($tool->getSchema(), $arguments);

        if ([] !== $errors) {
            $this->appendEvent(
                $run,
                RunEventType::ToolValidationError,
                ['tool' => $toolName, 'detail' => implode('; ', $errors), 'origin' => 'harness', 'attempt' => 1, 'toolCallId' => $callId],
                errorClass: ErrorClass::InvalidArguments,
                attemptNo: 1,
            );
            $this->em->flush();

            return [
                'toolCallId' => $callId,
                'content' => ToolCallPrimitives::errorFeedbackJson('invalid_arguments', $toolName, implode('; ', $errors), 1),
            ];
        }

        $this->appendEvent(
            $run,
            RunEventType::ToolCall,
            ['tool' => $toolName, 'arguments' => $arguments, 'origin' => 'harness', 'attempt' => 1, 'toolCallId' => $callId],
            attemptNo: 1,
        );

        $start = microtime(true);

        try {
            $content = $this->sessionToolRunner->call($tool, $arguments, $run->getTask());
        } catch (SessionMemoryWriteException $e) {
            // The store refused the write. Same shape as a schema refusal on
            // the MCP side: classified, recorded, fed back.
            $this->appendEvent(
                $run,
                RunEventType::ToolResult,
                ['tool' => $toolName, 'detail' => $e->getMessage(), 'origin' => 'harness', 'attempt' => 1, 'toolCallId' => $callId],
                errorClass: ErrorClass::InvalidArguments,
                attemptNo: 1,
            );
            $this->em->flush();

            return [
                'toolCallId' => $callId,
                'content' => ToolCallPrimitives::errorFeedbackJson('invalid_arguments', $toolName, $e->getMessage(), 1),
            ];
        }

        // Stored whole, as on the MCP side: the bound is a rendering decision
        // and lives in the window, not in the ledger.
        $this->appendEvent(
            $run,
            RunEventType::ToolResult,
            ['tool' => $toolName, 'content' => $content, 'isError' => false, 'origin' => 'harness', 'attempt' => 1, 'toolCallId' => $callId],
            attemptNo: 1,
            durationMs: (int) round((microtime(true) - $start) * 1000),
        );
        $this->em->flush();

        return ['toolCallId' => $callId, 'content' => $this->context->capToolResult($content)];
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
     * The transcript as the context compiler wants it: the stream's tail,
     * folded into exchange windows (docs/design/THE_STREAM.md §2.1).
     *
     * This is the read that replaced `$state->exchanges`. The window is a view
     * onto the stream rather than a copy carried between turns, which is what
     * lets a turn outlive the run that wrote it — and, eventually, the only
     * thing that makes a slice boundary invisible.
     *
     * The bound is a read guard, not a trim: a stream that ends can only need
     * as many rows as the window could possibly keep, and asking for the whole
     * history to then throw most of it away is how a long-lived being turns one
     * request into a full-table scan.
     *
     * @return list<array<string, mixed>>
     */
    private function streamExchanges(): array
    {
        return StreamWindow::toExchanges($this->stream->latest(self::STREAM_ROW_WINDOW));
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

        // The fleet id is stamped on the claim so boot recovery can ask the
        // question recency cannot answer: *whose* claim is this? (SPEC §6.2,
        // App\RunEngine\FleetId). Null for a process with no fleet identity —
        // a one-shot app:run:now, say — which is why the column is nullable
        // and why the reaper treats null as "proven nothing, so do nothing".
        $updated = $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = :now, claim_fleet = :fleet WHERE id = :id AND (claimed_at IS NULL OR claimed_at <= :staleBefore)',
            [
                'now' => $now,
                'fleet' => FleetId::current(),
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
            'UPDATE run SET claimed_at = NULL, claim_fleet = NULL WHERE id = :id AND lock_version = :token',
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
     * Record that the context window had to shed part of its tail to fit
     * the budget (SPEC §5.3, §5.6). The adaptive trim means a run can now
     * proceed with a shorter window instead of failing closed — so the
     * shedding must be visible: a `context_trim` row says how many of the
     * newest exchanges were kept and how many were dropped, which is the
     * difference between "this run was near the limit" and "this run is
     * quietly losing its early tool results". Absent a trim it writes
     * nothing, so the common path gains no ledger row.
     *
     * Flushed with the turn's next commit (this rides the same unit of
     * work as the LLM response): a trim that is not persisted because the
     * process died mid-request is a diagnostic that was never owed.
     */
    private function recordTrim(Run $run, LoopState $state, ContextWindowResult $fit): void
    {
        if (!$fit->trimmed()) {
            return;
        }

        $this->appendEvent($run, RunEventType::ContextTrim, [
            'keptExchanges' => $fit->keptExchanges,
            'droppedExchanges' => $fit->droppedExchanges,
            'estimatedTokens' => $fit->estimatedTokens,
            'limitTokens' => $fit->limitTokens,
            'contextBase' => $state->contextBase,
        ]);
    }

    /**
     * Advance the run's sticky shed base from a fit (docs/design/KV_CACHE.md
     * §6.2).
     *
     * The base is the index the retained window's front stands at. It moves
     * ONLY when the budget forced a shed: the retained tail is now the newest
     * `keptExchanges`, so the front is wherever that window begins. Between
     * sheds it does not move at all — that stillness is the whole point, and
     * the reason this is done here rather than by re-slicing to the newest N
     * every turn (which is B2).
     *
     * Batched mode latches on for the run's remaining turns the first time a
     * trim fires: a run that has once proved it can overflow the budget is the
     * one that benefits from shedding to the headroom target on every later
     * forced trim, and latching keeps the choice a property of the run rather
     * than of a live environment read. A run that never trims keeps the empty
     * base (null) and the window's own stateless bound, which is the old shape.
     */
    private function advanceContextBase(LoopState $state, ContextWindowResult $fit, int $candidateCount): void
    {
        if (!$fit->trimmed()) {
            return;
        }

        $state->contextBase = $candidateCount - $fit->keptExchanges;
        $state->contextBatch = true;
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
