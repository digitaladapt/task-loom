<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\Step;
use App\Entity\Task;
use App\Message\LlmTurnMessage;
use App\Repository\RunRepository;
use App\Repository\StepRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The step model's run graph (SPEC §13.3): a stepped task's run is a parent
 * Run, one child Run per step, and one final-consumer child. A task with
 * zero steps never comes here — the zero-step path stays the v1 path
 * (SPEC §13.1).
 *
 * Advancement is STATE-DERIVED, never cursor-driven: plan() answers "what
 * does the committed graph owe" as a pure function of the rows — which
 * steps are ready, whether the final consumer is due, whether the parent
 * settles — and the engine applies the plan from inside the same
 * terminal-commit transaction that settled the triggering child. There is
 * no orchestrator, no orchestrator run, no new liveness to babysit: the
 * same dispatch-from-committed-state pattern the turn engine already
 * trusts.
 *
 * Concurrency safety rides the same rails as the turn engine. The single
 * writer (SQLite here; the transport lane model generally) serializes graph
 * transactions, and each such transaction's first write is the settled
 * child's terminal state — after that write, the derivation reads fresh
 * committed state, so a second finisher cannot miss the first finisher's
 * newly created children and cannot double-create them. Duplicate
 * deliveries are adjudicated below this layer by the execution claim;
 * here, the state checks make re-derivation idempotent.
 *
 * Failure policy is strict and fail-closed (SPEC §13.5): any terminal
 * non-success child settles the parent with that child's state and error
 * class, downstream steps are never dispatched, and the final consumer
 * never runs. Children already in flight finish — there is no cancellation
 * machinery, and a half-run child has nothing left to corrupt.
 */
final class RunGraph
{
    /** Safety valve for one advancement pass: dispatch → derive → settle converges in a few passes. */
    private const int MAX_ADVANCE_PASSES = 64;

    /**
     * @param array<string, int> $budgets step_budget, tool_retries, circuit_breaker
     */
    public function __construct(
        private readonly RunRepository $runs,
        private readonly StepRepository $steps,
        private readonly StepOutputProvider $outputs,
        private readonly EntityManagerInterface $em,
        private readonly ToolboxResolver $resolver,
        private readonly PromptCompiler $prompts,
        private readonly LoggerInterface $logger,
        private readonly array $budgets = [],
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    /** Whether this task decomposes into steps at all (SPEC §13.1). */
    public function hasSteps(Task $task): bool
    {
        return [] !== $this->steps->findForTask($task);
    }

    /**
     * Create the parent run and dispatch the root steps (SPEC §13.3): the
     * parent row, every root-step child, and their first lane messages
     * commit in one transaction — the same invariant as a turn commit, so
     * there is no window where the graph exists without its carriers.
     */
    public function beginGraph(Task $task, bool $async): Run
    {
        $parent = new Run($task);
        $parent->setRole(RunRole::Parent);
        $parent->markStarted();

        $this->transactional(function () use ($parent, $async): void {
            $this->em->persist($parent);
            $this->em->flush(); // the parent's id: children reference it, messages carry it

            $this->advance($parent, $async);
        });

        return $parent;
    }

    /**
     * A queued child whose graph has already settled: it was created and
     * dispatched before a sibling's failure decided the outcome, and it
     * must not start now. A RUNNING child is mid-work and proceeds — the
     * spec's "in-flight children finish" (SPEC §13.5).
     */
    public function isSkipped(Run $run): bool
    {
        $parent = $run->getParent();
        if (null === $parent) {
            return false;
        }

        $this->em->refresh($parent);

        return $parent->isTerminal() && RunStatus::Queued === $run->getStatus();
    }

    /**
     * The next child a synchronous driver should execute: creation order,
     * first non-terminal child. Null when the parent has settled (queued
     * siblings of a failed graph are orphans and are not driven, SPEC
     * §13.5) or when the graph is mid-flight on lanes this process does not
     * drive.
     */
    public function nextExecutable(Run $parent): ?Run
    {
        if (RunRole::Parent !== $parent->getRole()) {
            return null;
        }

        $this->em->refresh($parent);
        if ($parent->isTerminal()) {
            return null;
        }

        foreach ($this->runs->findChildren($parent) as $child) {
            $this->em->refresh($child);
            if (!$child->isTerminal()) {
                return $child;
            }
        }

        return null;
    }

    /**
     * A child reached a terminal state — derive and apply what the graph
     * now owes (SPEC §13.3). Called by the engine from inside the terminal
     * commit transaction, so the settled child, the newly dispatched
     * siblings, and their messages are one atomic step.
     */
    public function onTerminal(Run $child, bool $async): void
    {
        $parent = $child->getParent();
        if (null === $parent) {
            return;
        }

        $this->transactional(function () use ($parent, $async): void {
            $this->advance($parent, $async);
        });
    }

    /**
     * Derive and apply until nothing is owed: dispatch ready steps, then
     * the final consumer, settle the parent. Iterating matters — a child
     * whose constitution cannot resolve (a tool missing from the catalog,
     * an empty toolbox) fails immediately at creation, and that failure
     * must settle the parent in this same commit rather than stranding the
     * graph.
     */
    private function advance(Run $parent, bool $async): void
    {
        for ($pass = 0; $pass < self::MAX_ADVANCE_PASSES; ++$pass) {
            $plan = $this->plan($parent);
            if ($plan->isIdle()) {
                return;
            }

            $this->apply($parent, $plan, $async);

            if (null !== $plan->settleStatus) {
                return;
            }
        }

        throw new \LogicException(\sprintf('Step graph advancement of run %d did not converge.', $parent->getId()));
    }

    /**
     * What the committed graph owes right now (SPEC §13.3, §13.5). Pure
     * derivation: it reads, it never writes. Parent and children are
     * refreshed first — under the async lanes, a worker's identity map may
     * hold instances from earlier turns, and sibling rows are written by
     * other workers between deliveries.
     */
    private function plan(Run $parent): GraphPlan
    {
        $this->em->refresh($parent);
        if ($parent->isTerminal()) {
            return GraphPlan::idle();
        }

        /** @var list<Run> $children */
        $children = [];
        foreach ($this->runs->findChildren($parent) as $child) {
            $this->em->refresh($child);
            $children[] = $child;
        }

        $steps = $this->steps->findForTask($parent->getTask());

        // 1. Strict fail-closed (SPEC §13.5): the earliest terminal
        //    non-success child decides the graph's outcome.
        foreach ($children as $child) {
            if ($child->isTerminal() && RunStatus::Succeeded !== $child->getStatus()) {
                return $this->settleFromFailure($child);
            }
        }

        /** @var array<int, true> $dispatchedStepIds */
        $dispatchedStepIds = [];
        /** @var array<int, true> $succeededStepIds */
        $succeededStepIds = [];
        $final = null;

        foreach ($children as $child) {
            if (RunRole::Step === $child->getRole() && null !== $child->getStep()) {
                $stepId = $child->getStep()->getId();
                if (null !== $stepId) {
                    $dispatchedStepIds[$stepId] = true;
                    if (RunStatus::Succeeded === $child->getStatus()) {
                        $succeededStepIds[$stepId] = true;
                    }
                }
            }

            if (RunRole::FinalConsumer === $child->getRole()) {
                $final = $child;
            }
        }

        // 2. A step whose dependencies have all succeeded and which has no
        //    child run yet is ready — the same derivation that launches
        //    root steps (dependencies = ∅) and mid-DAG steps alike.
        $ready = [];
        foreach ($steps as $step) {
            $stepId = $step->getId();
            if (null === $stepId || isset($dispatchedStepIds[$stepId])) {
                continue;
            }

            $blocked = false;
            foreach ($step->getDependsOn() as $dependency) {
                // The declared type is list<int>; a corrupt or hand-written
                // row may still carry anything (the enable gate prevents it,
                // SPEC §13.2, but malformed data must fail SAFE). A non-int
                // dependency coerces to an id that cannot match a succeeded
                // step, so the step blocks rather than dispatching wrongly.
                if (!isset($succeededStepIds[(int) $dependency])) {
                    $blocked = true;
                    break;
                }
            }

            if (!$blocked) {
                $ready[] = $step;
            }
        }

        if ([] !== $ready) {
            return GraphPlan::dispatch($ready);
        }

        // 3. The final consumer (the task itself) is due once every step
        //    has succeeded — and only then (SPEC §13.1).
        if (null === $final) {
            if (\count($succeededStepIds) === \count($steps)) {
                return GraphPlan::dispatchFinal();
            }

            // Nothing ready and nothing in flight: no step can ever become
            // dispatchable (a dependency that cannot be satisfied — the
            // enable gate prevents it, SPEC §13.2, but corrupt or
            // hand-written rows must fail SAFE). Settle for attention with
            // a diagnosis; a silently wedged graph is the one outcome the
            // fail-closed philosophy refuses (SPEC §13.5).
            $inFlight = false;
            foreach ($children as $child) {
                if (!$child->isTerminal()) {
                    $inFlight = true;
                    break;
                }
            }

            if (!$inFlight) {
                $blocked = \count($steps) - \count($dispatchedStepIds);

                return GraphPlan::settle(
                    RunStatus::NeedsAttention,
                    ErrorClass::Unknown,
                    \sprintf(
                        'The step graph cannot progress: %d step(s) can never be dispatched (a dependency that cannot be satisfied). The graph data may be corrupt or have bypassed the enable gate (SPEC §13.2).',
                        $blocked,
                    ),
                );
            }

            return GraphPlan::idle();
        }

        // 4. The final consumer's outcome settles the parent; while it is
        //    in flight, the graph waits.
        if (RunStatus::Succeeded === $final->getStatus()) {
            return GraphPlan::settle(RunStatus::Succeeded);
        }

        return GraphPlan::idle();
    }

    /**
     * Apply one plan (SPEC §13.3): create the children it dispatches — each
     * child's row and its first lane message commit together — and settle
     * the parent when the outcome is decided.
     */
    private function apply(Run $parent, GraphPlan $plan, bool $async): void
    {
        foreach ($plan->readySteps as $step) {
            $child = $this->createChild($parent, RunRole::Step, $step);
            $this->dispatchFirstTurn($child, $async);
        }

        if ($plan->finalReady) {
            $child = $this->createChild($parent, RunRole::FinalConsumer, null);
            $this->dispatchFirstTurn($child, $async);
        }

        if (null !== $plan->settleStatus) {
            $this->settleParent($parent, $plan);
        }
    }

    /**
     * Create one child run with its frozen constitution (SPEC §4.1, §13.1),
     * exactly as the engine freezes a standalone run's: the resolved
     * toolbox snapshot (never re-resolved mid-run) and the compiled prompt
     * head.
     *
     * A declaration that cannot resolve — a tool that is not in the
     * catalog, a toolbox that resolves empty — fails the child LOUDLY as a
     * classified ledger row instead of throwing. This runs inside a
     * terminal commit's transaction; throwing would roll back the settled
     * sibling whose advancement is in progress, stranding it and looping
     * its redelivery. The failed child settles the parent through the
     * ordinary derivation (SPEC §13.5).
     */
    private function createChild(Run $parent, RunRole $role, ?Step $step): Run
    {
        $task = $parent->getTask();

        $child = new Run($task);
        $child->setRole($role);
        $child->setParent($parent);
        if (null !== $step) {
            $child->setStep($step);
        }

        try {
            $tools = null === $step ? $this->resolver->resolve($task) : $this->resolver->resolveStep($step);
            $promptHead = match (true) {
                null !== $step => $this->prompts->compileForStep($task, $step, $tools, $this->outputs->forStep($parent, $step)),
                RunRole::FinalConsumer === $role => $this->prompts->compileForFinalConsumer($task, $tools, $this->outputs->forFinalConsumer($parent)),
                default => $this->prompts->compile($task, $tools),
            };

            $child->setToolboxSnapshot(ToolboxSnapshot::fromTools($tools));
            $child->setCheckpoint((new LoopState(
                stepBudget: $this->budgets['step_budget'] ?? 50,
                toolRetries: $this->budgets['tool_retries'] ?? 2,
                circuitBreakerThreshold: $this->budgets['circuit_breaker'] ?? 3,
                promptHead: $promptHead,
            ))->toCheckpoint());
        } catch (ToolboxResolutionException|StepOutputException $e) {
            $child->markFailed($e->errorClass);
            $this->appendEvent($child, RunEventType::Failure, ['reason' => $e->getMessage()], $e->errorClass);

            $this->logger->error('Child run of task {task} failed at dispatch: {reason}', [
                'task' => $task->getId(),
                'reason' => $e->getMessage(),
            ]);
        }

        $this->em->persist($child);
        $this->em->flush(); // the child's id: its message carries it

        return $child;
    }

    /**
     * In async mode a child's first turn is a lane message, enqueued inside
     * the graph's transaction (the transport shares this connection) — the
     * child row and its carrier commit together. In sync mode the caller
     * drives the child inline via nextExecutable(). A child that already
     * failed at dispatch owes nothing.
     */
    private function dispatchFirstTurn(Run $child, bool $async): void
    {
        if (!$async || !$child->executesTurns() || $child->isTerminal()) {
            return;
        }

        $bus = $this->bus ?? throw new \LogicException('RunGraph has no message bus configured — async graph dispatch requires it.');

        $bus->dispatch(new LlmTurnMessage((int) $child->getId(), 1));
    }

    /**
     * The graph's outcome is decided: the parent carries the failing
     * child's terminal state and error class (SPEC §13.5) — the attention
     * queue and the run surface show the precise diagnosis without
     * unwrapping the graph — or succeeds with the final consumer's
     * completion artifact as its own, the run's deliverable (SPEC §13.4).
     */
    private function settleParent(Run $parent, GraphPlan $plan): void
    {
        if (RunStatus::Succeeded === $plan->settleStatus) {
            $parent->markSucceeded();
            $result = $this->finalArtifact($parent);
            $this->appendEvent(
                $parent,
                RunEventType::Completion,
                null === $result ? [] : ['result' => $result],
            );
        } else {
            $parent->settleAs($plan->settleStatus, $plan->settleError);
            $this->appendEvent(
                $parent,
                RunEventType::Failure,
                ['reason' => $plan->settleReason ?? 'the step graph did not complete'],
                $plan->settleError,
            );
        }

        $this->em->flush();
    }

    /**
     * The failing child, rendered once for the parent's ledger and status
     * (SPEC §13.5): which step (or the final consumer), which terminal
     * state, which error class.
     */
    private function settleFromFailure(Run $child): GraphPlan
    {
        $status = $child->getStatus();
        $error = $child->getErrorClass();
        $suffix = null === $error ? '' : ': '.$error->value;

        $step = $child->getStep();
        $what = null !== $step
            ? \sprintf('Step "%s"', $step->getTitle())
            : 'The final consumer';

        $reason = \sprintf(
            '%s (run #%d) did not succeed (%s%s). Downstream steps and the final consumer do not run (SPEC §13.5).',
            $what,
            $child->getId(),
            $status->value,
            $suffix,
        );

        return GraphPlan::settle($status, $error, $reason);
    }

    /**
     * The final consumer's justified completion artifact (SPEC §13.4) —
     * copied onto the parent so the run itself is self-contained in the
     * ledger. Read through the output provider: one artifact-reading path.
     */
    private function finalArtifact(Run $parent): ?string
    {
        $final = null;
        foreach ($this->runs->findChildren($parent) as $child) {
            if (RunRole::FinalConsumer === $child->getRole()) {
                $final = $child;
                break;
            }
        }

        if (null === $final) {
            return null;
        }

        try {
            return $this->outputs->artifactOf($final);
        } catch (StepOutputException) {
            // The parent is settling as succeeded only because the final
            // consumer succeeded; a missing artifact here is corrupt state,
            // surfaced as a parent completion with no result rather than a
            // throw out of the settle path.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendEvent(Run $run, RunEventType $type, array $payload, ?ErrorClass $errorClass = null): void
    {
        $event = new RunEvent($type);
        $event->setPayload($payload);
        if (null !== $errorClass) {
            $event->setErrorClass($errorClass);
        }

        $run->appendEvent($event);
        $this->em->persist($event);
    }

    /**
     * Run $writes as one transaction on the shared connection, mirroring
     * the engine's commitTurn: full rollback on any throw, so a graph that
     * cannot be applied cleanly never half-persists. When the caller
     * already holds a transaction (a terminal commit's), this joins it.
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
