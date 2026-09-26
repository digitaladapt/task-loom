<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;
use App\Entity\RunStatus;
use App\Entity\Step;

/**
 * What a stepped task's run graph owes, derived from committed state
 * (SPEC §13.3): which steps are newly ready to dispatch, whether the final
 * consumer is due, and whether the parent settles.
 *
 * Pure data — the derivation lives in RunGraph::plan(), the application in
 * RunGraph::apply(). Deriving instead of remembering is the same discipline
 * the turn engine trusts: given the committed rows, the owed work is a
 * function of state, not a mutable cursor that can drift.
 */
final readonly class GraphPlan
{
    /**
     * @param list<Step>     $readySteps   steps with no child run yet whose
     *                                     dependencies have all succeeded
     * @param bool           $finalReady   every step succeeded and the final
     *                                     consumer has not been dispatched
     * @param RunStatus|null $settleStatus the parent's terminal state, when
     *                                     the graph's outcome is decided
     */
    private function __construct(
        public array $readySteps = [],
        public bool $finalReady = false,
        public ?RunStatus $settleStatus = null,
        public ?ErrorClass $settleError = null,
        public ?string $settleReason = null,
    ) {
    }

    /** Nothing owed: the graph is waiting on in-flight children, or has settled. */
    public static function idle(): self
    {
        return new self();
    }

    /**
     * @param list<Step> $steps
     */
    public static function dispatch(array $steps): self
    {
        return new self(readySteps: $steps);
    }

    public static function dispatchFinal(): self
    {
        return new self(finalReady: true);
    }

    /**
     * The graph's outcome is decided: the parent settles as $status,
     * carrying the failing child's error class and a human-readable reason
     * (SPEC §13.5). Success settles here too — with no error and no reason,
     * because there is nothing to diagnose.
     */
    public static function settle(RunStatus $status, ?ErrorClass $error = null, ?string $reason = null): self
    {
        return new self(settleStatus: $status, settleError: $error, settleReason: $reason);
    }

    public function isIdle(): bool
    {
        return [] === $this->readySteps && !$this->finalReady && null === $this->settleStatus;
    }
}
