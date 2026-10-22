<?php

declare(strict_types=1);

namespace App\Session;

use App\Context\Grounding;
use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\RunTrigger;
use App\Entity\SessionDeclarationOutcome;
use App\Entity\Task;
use App\Message\SessionTurnMessage;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\LoopState;
use App\RunEngine\PromptCompiler;
use App\RunEngine\ToolboxResolutionException;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolboxSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The session slice engine (docs/design/SESSION_TASKS.md §8, build order
 * step 4): deriving a session's state, creating slice runs, and launching
 * the next slice the moment a `milestone` settles — the same
 * dispatch-from-committed-state pattern `RunGraph` established for steps,
 * applied to the session's slice sequence.
 *
 * Three launch paths, three postures:
 *
 *  - {@see self::start()} — the operator's start/resume (Run now on an
 *    enabled session; the launcher carries the first turn). Refuses what an
 *    operator action must refuse loudly: a slice already in flight, a
 *    completed session, a paused one. A constitution that cannot resolve
 *    throws, exactly as it does for an ordinary task's launch — the
 *    operator sees why on the spot.
 *  - {@see self::advance()} — called by the engine *inside* the settlement
 *    commit when a slice declared a `milestone`; the next slice's row and
 *    its first lane message commit with the settlement itself (§8's "the
 *    same transaction that commits that state", one level down). It never
 *    throws for a constitution that cannot resolve — this runs inside a
 *    terminal commit, and throwing would roll back the settlement — so a
 *    failed slice is recorded LOUDLY as a terminal ledger row instead, and
 *    the session derives `stopped` for a human. That also converges: a
 *    broken constitution cannot be retried into an infinite loop.
 *  - {@see self::reconcile()} — the requeue sweep's session pass: for every
 *    enabled session, re-derive whether the next slice is `owed` and launch
 *    it. Same posture as `RunGraph::reconcileParents`: it repairs what no
 *    turn dispatch can see (a next slice the committed state owes but that
 *    no row or message carries), and converges to nothing when healthy.
 *
 * A session runs **one slice at a time**: every launch path checks for an
 * active top-level run first, and `advance()` only ever fires from a
 * settlement (which by definition is the only slice that was in flight).
 */
final readonly class SessionSliceEngine
{
    /**
     * @param array<string, int> $budgets slice_budget, tool_retries, circuit_breaker
     */
    public function __construct(
        private TaskRepository $tasks,
        private RunRepository $runs,
        private ToolboxResolver $resolver,
        private PromptCompiler $prompts,
        private Grounding $grounding,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private array $budgets = [],
        private SessionEndings $endings = new SessionEndings(),
        private SessionRetry $retry = new SessionRetry(),
    ) {
    }

    /**
     * A session's derived state (docs/design/SESSION_TASKS.md §8): the
     * task's enabled flag, its top-level runs, and their declarations —
     * nothing else. `paused` is derived first, because nothing launches
     * while the operator's switch is off.
     */
    public function state(Task $task): SessionState
    {
        if (!$task->isEnabled()) {
            return SessionState::Paused;
        }

        return $this->activeState($task);
    }

    /**
     * The state the session would be in if it were enabled — the same
     * derivation as {@see self::state()} without the operator's switch. The
     * UI (step 6) reads this to say what a resume would actually do: a
     * paused session whose last slice declared `done` resumes to
     * `complete`, and enabling it launches nothing.
     */
    public function activeState(Task $task): SessionState
    {
        $runs = $this->runs->findForChain($task);

        foreach ($runs as $run) {
            if (!$run->isTerminal()) {
                return SessionState::Working;
            }
        }

        if ([] === $runs) {
            return SessionState::Fresh;
        }

        // Newest first (findForChain): the last slice's declaration decides —
        // and when there is none, the policy the slice froze does
        // (docs/design/SESSION_ENDINGS.md §4): a succeeded, undeclared
        // slice under `continue` is the same owed-next-slice shape a
        // milestone is, so a lost launch is repaired by the sweep the same
        // way. Everything that actually went wrong — a failed turn, an
        // exhausted budget, a tripped breaker — is not `succeeded` and
        // still derives `stopped`: §8's "no automatic slice retry" stays
        // true for everything that broke.
        $last = $runs[0];

        // A retryable failure is not "stopped" — it is owed on a clock. The
        // retry assessment is bounded by construction ({@see SessionRetry}),
        // so this cannot become an endless loop: once the budget or the age
        // ceiling is spent it reports exhausted, and the `stopped` arm below
        // takes over — which is what raises the container alarm.
        $retry = $this->retry->assess($runs, time());

        if (null !== $retry && !$retry->exhausted()) {
            return SessionState::Retrying;
        }

        return match ($last->getDeclarationOutcome()) {
            SessionDeclarationOutcome::Milestone => SessionState::Owed,
            SessionDeclarationOutcome::Done => SessionState::Complete,
            SessionDeclarationOutcome::Blocked => SessionState::Blocked,
            null => self::continuesUndeclared($last) ? SessionState::Owed : SessionState::Stopped,
        };
    }

    /**
     * The session's newest slice, or null when none has run — what the UI and
     * the alarm report. Top-level runs only (a slice is the session's own
     * run), newest first, which is the order that makes [0] the answer.
     */
    public function lastSlice(Task $task): ?Run
    {
        return $this->runs->findForChain($task)[0] ?? null;
    }

    /**
     * The session's retry condition, for the page and the alarm: null when the
     * newest slice is not a retryable failure.
     */
    public function retryState(Task $task, ?int $now = null): ?SessionRetryState
    {
        return $this->retry->assess($this->runs->findForChain($task), $now ?? time());
    }

    /**
     * Whether the sweep should launch a slice for this session on THIS pass.
     *
     * `owed` always launches. `retrying` launches only once the backoff
     * delay has elapsed — that is the whole point of separating the two:
     * the session is owed a slice either way, but a retry that ignores its
     * own backoff would hammer a failing provider and burn slices.
     *
     * The `retrying` arm re-reads the assessment rather than trusting the
     * state label, because the state says "retrying" whether the clock has
     * come round or not, and the clock is the only thing that distinguishes
     * a launch from a wait.
     */
    private function launchable(Task $task): bool
    {
        $state = $this->state($task);

        if (SessionState::Owed === $state) {
            return true;
        }

        if (SessionState::Retrying !== $state) {
            return false;
        }

        $retry = $this->retry->assess($this->runs->findForChain($task), time());

        return null !== $retry && $retry->due(time());
    }

    /**
     * Whether a terminal slice is an undeclared continuation: it succeeded
     * with no declaration, and its frozen policy says an undeclared
     * settlement continues. Read from the checkpoint — the same frozen
     * source the settlement itself judged by.
     */
    private static function continuesUndeclared(Run $slice): bool
    {
        if (RunStatus::Succeeded !== $slice->getStatus()) {
            return false;
        }

        $undeclared = $slice->getCheckpoint()['undeclared'] ?? null;

        return $undeclared instanceof SessionUndeclared
            ? SessionUndeclared::Continue === $undeclared
            : SessionUndeclared::Continue->value === $undeclared;
    }

    /**
     * The operator's start/resume: create the next slice for an enabled
     * session whose state allows one. The caller dispatches the first turn
     * (RunLauncher carries it), so a crash between the run row committing
     * and the dispatch is repaired by the requeue sweep's turn pass — the
     * same recovery story as an ordinary task.
     *
     * Allowed states: `fresh` (start), `owed` (resume after a lost
     * dispatch), `blocked` and `stopped` (the operator's resume — one
     * action on a page that says why it stopped). Refused: `working` (one
     * slice at a time), `complete` (`done` is terminal; a new session or an
     * approved replacement continues the work), `paused` (the operator's
     * switch is off).
     *
     * @throws SessionConstraintException when the session's state refuses a new slice
     * @throws ToolboxResolutionException when the slice's constitution cannot resolve
     */
    public function start(Task $task): Run
    {
        match ($this->state($task)) {
            SessionState::Working => throw SessionConstraintException::sliceInFlight(),
            SessionState::Complete => throw SessionConstraintException::complete(),
            SessionState::Paused => throw SessionConstraintException::paused(),
            default => null,
        };

        return $this->createSlice($task, RunTrigger::Manual, dispatch: false, recordFailures: false);
    }

    /**
     * The next slice, due because a `milestone` settled — launched inside
     * the settlement commit that recorded the declaration. A session that
     * was paused mid-slice launches nothing (the current slice finishing
     * quietly is the pause contract); its owed slice is re-derived when the
     * operator re-enables it and the sweep runs, or by Run now.
     *
     * Never throws: this runs inside the terminal commit, and a throw would
     * roll the settlement back. A constitution that cannot resolve records
     * a terminal slice row instead (see the class note); a one-slice-at-a-
     * time refusal (a concurrent actor sliced first) is a benign skip — the
     * declaration stands, the slice stays `owed`, and the sweep picks it
     * up. The next slice always dispatches to the lanes (sessions are
     * lane-driven, §7); returns the created slice — settled or
     * failed-at-dispatch — or null when nothing launched.
     */
    public function advance(Run $settledSlice): ?Run
    {
        $task = $settledSlice->getTask();

        if (!$task->isEnabled()) {
            return null;
        }

        try {
            return $this->createSlice($task, RunTrigger::Continuation, dispatch: true, recordFailures: true);
        } catch (SessionConstraintException) {
            return null;
        }
    }

    /**
     * The requeue sweep's session pass, over every enabled session: derive
     * whether the next slice is owed and launch it (or report it, in a dry
     * run). Converges to nothing: a launched slice flips the state to
     * `working`, and every other state owes nothing here — `fresh` sessions
     * wait for the operator's start, `blocked`/`stopped` wait for a human,
     * `complete`/`paused` are terminal for this purpose, and `retrying` is
     * owed but not yet due (it is launchable, just not on every pass — see
     * {@see self::launchable()}).
     *
     * @return list<SessionReconcile>
     */
    public function reconcile(bool $dryRun = false): array
    {
        $reconciles = [];

        foreach ($this->tasks->findEnabledSessions() as $task) {
            if (null === $task->getId()) {
                continue;
            }

            if (!$this->launchable($task)) {
                continue;
            }

            if ($dryRun) {
                $reconciles[] = new SessionReconcile((int) $task->getId(), applied: false, sliceRunId: null);

                continue;
            }

            try {
                $slice = $this->launchOwed($task);
            } catch (SessionConstraintException) {
                // Another actor sliced first (the operator, a concurrent
                // sweep): converged — nothing is owed anymore.
                continue;
            }

            $reconciles[] = new SessionReconcile((int) $task->getId(), applied: true, sliceRunId: null === $slice->getId() ? null : (int) $slice->getId());
        }

        return $reconciles;
    }

    /**
     * One session's slice reconciliation — the scoped form of
     * {@see self::reconcile()}, for the sweep invoked with a single run in
     * hand. Null when the session owes nothing.
     */
    public function reconcileTask(Task $task, bool $dryRun = false): ?SessionReconcile
    {
        if (null === $task->getId()) {
            return null;
        }

        if (!$this->launchable($task)) {
            return null;
        }

        if ($dryRun) {
            return new SessionReconcile((int) $task->getId(), applied: false, sliceRunId: null);
        }

        try {
            $slice = $this->launchOwed($task);
        } catch (SessionConstraintException) {
            return null;
        }

        return new SessionReconcile((int) $task->getId(), applied: true, sliceRunId: null === $slice->getId() ? null : (int) $slice->getId());
    }

    /**
     * Launch the owed slice outside a settlement: its own transaction, with
     * the first turn dispatched inside it (row and carrier commit
     * together — no window for a crash to fall into). A constitution that
     * cannot resolve is recorded as a failed slice row rather than thrown:
     * the sweep must not abort mid-pass, and the failure is exactly what
     * the session's derived state shows a human.
     */
    private function launchOwed(Task $task): Run
    {
        return $this->createSlice($task, RunTrigger::Continuation, dispatch: true, recordFailures: true);
    }

    /**
     * Create one slice run — a session's ordinary run, frozen exactly as
     * the engine freezes any run's constitution (SPEC §4.1), with two
     * session-specific pieces: the slice budget (`slice_budget`) instead of
     * the step budget, and the slice prompt head, whose completion text is
     * the declaration contract (`PromptCompiler::compileForSlice`).
     *
     * `$recordFailures` picks the failure posture: false (the operator's
     * start) lets a resolution failure throw, so the trigger's surface says
     * why; true (settlement and sweep) records the failure as a terminal
     * slice row and returns it, because throwing would roll back a
     * settlement or abort a sweep pass — and because a recorded failure is
     * what makes the session derive `stopped` instead of re-owing the same
     * unbuildable slice forever.
     */
    private function createSlice(Task $task, RunTrigger $trigger, bool $dispatch, bool $recordFailures): Run
    {
        // A scheduled session is refused here, where every launch path meets
        // (docs/design/SESSION_TASKS.md §8: "No schedule: a session is started
        // and stopped, not cron'd"). The write and enable gates already
        // refuse to CREATE one; a row that reached the store by surgery must
        // not become a cron-launched session either — the scheduler's door
        // fails it loudly as a classified dispatch failure, and no other
        // path can slip a scheduled session onto the lanes.
        if (null !== $task->getSchedule()) {
            throw SessionConstraintException::scheduleNotAllowed();
        }

        $run = new Run($task);
        $run->setTriggeredBy($trigger);

        try {
            $tools = $this->resolver->resolve($task);

            // Fix this slice's grounding to the session's anchor before the
            // head is compiled, so slices within a deployment day compile a
            // byte-identical head and reuse each other's prefix rather than
            // each re-prefilling a fresh one (docs/design/KV_CACHE.md §6.3,
            // B4). The anchor is written on the task, which the flush below
            // persists with the slice row.
            $groundingAt = $this->groundingInstantFor($task);

            $run->setToolboxSnapshot(ToolboxSnapshot::fromDefinitions($tools));
            $run->setCheckpoint((new LoopState(
                stepBudget: $this->budgets['slice_budget'] ?? 25,
                toolRetries: $this->budgets['tool_retries'] ?? 2,
                circuitBreakerThreshold: $this->budgets['circuit_breaker'] ?? 3,
                promptHead: $this->prompts->compileForSlice($task, $tools, $this->endings, $groundingAt),
                // The endings policy is constitution, frozen per slice
                // (docs/design/SESSION_ENDINGS.md §2): the settlement's
                // judgment — and the streak it counts — read it from the
                // checkpoint, so a knob that moves mid-slice cannot change
                // what this slice's ending means.
                undeclared: $this->endings->undeclared,
                maxUndeclared: $this->endings->maxUndeclared,
            ))->toCheckpoint());
        } catch (ToolboxResolutionException $e) {
            if (!$recordFailures) {
                throw $e;
            }

            $run->markFailed($e->errorClass);
            $this->appendFailure($run, $e->getMessage(), $e->errorClass);
            $this->em->persist($run);
            $this->em->flush();

            return $run;
        }

        $this->transactional(function () use ($run, $dispatch, $task): void {
            // One slice at a time, re-checked under this transaction:
            // the operator's start and the requeue sweep can both read
            // `owed` before either writes, and the second writer must not
            // double-slice. The guard closes the common ordering; a race
            // that slips past it fails LOUDLY (SQLite refuses the stale-
            // snapshot write, the transaction rolls back) rather than
            // silently creating a second slice.
            if (0 !== $this->runs->countActiveTopLevelFor($task)) {
                throw SessionConstraintException::sliceInFlight();
            }

            $this->em->persist($run);
            $this->em->flush(); // the slice's id: its message carries it

            if ($dispatch) {
                // The session lane (docs/design/SESSION_TASKS.md §7): the
                // slice's first turn carries the class that routes it below
                // `llm`, so a session never competes with a task for the
                // next available worker.
                $this->bus->dispatch(new SessionTurnMessage((int) $run->getId(), 1));
            }
        });

        return $run;
    }

    /**
     * The instant this slice's grounding should be rendered as of, anchoring
     * the session if it has no anchor yet and moving the anchor when the
     * deployment day has turned (docs/design/KV_CACHE.md §6.3, B4).
     *
     * A session slice is re-compiled per slice; a live clock would make every
     * slice a brand-new head, so slice *n+1* re-prefills from scratch even
     * when the memory it carries is mostly the same text. Anchoring renders
     * the block as of the session's own past instead, byte-stable within a
     * day, and moves it once when the day turns — the freshness that matters
     * (a session resumed tomorrow is told tomorrow's date), at one cache
     * break a day rather than one a slice. A task with no anchor and no
     * creation time (not yet persisted) renders the live clock — it cannot be
     * anchored to a value it has nowhere to store.
     */
    private function groundingInstantFor(Task $task): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now');
        $anchor = $task->groundingInstant();

        if (null === $anchor) {
            return $now;
        }

        if ($this->grounding->calendarDay($anchor) !== $this->grounding->calendarDay($now)) {
            $task->regroundAt($now);

            return $now;
        }

        return $anchor;
    }

    private function appendFailure(Run $run, string $reason, ErrorClass $errorClass): void
    {
        $event = new RunEvent(RunEventType::Failure);
        $event->setPayload(['reason' => \sprintf('%s — the slice failed at dispatch; the session stops for a human (docs/design/SESSION_TASKS.md §8).', $reason)]);
        $event->setErrorClass($errorClass);

        $run->appendEvent($event);
        $this->em->persist($event);
    }

    /**
     * Run $writes as one transaction on the shared connection, joining a
     * caller's transaction when one is held — the same contract as
     * RunEngine::commitTurn and RunGraph::transactional: the settlement
     * path's slice row and the sweep's committed together with whatever
     * else the caller is committing.
     */
    private function transactional(callable $writes): void
    {
        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $writes();

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->em->clear();
            }

            throw $e;
        }
    }
}
