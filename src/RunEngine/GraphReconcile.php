<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\RunStatus;

/**
 * What one parent's graph reconciliation found and did (SPEC §13.3): in a
 * preview ($applied false), what the committed state owes; in an applied
 * reconcile, what was actually created and settled. Reporting only — the
 * repair itself is RunGraph::reconcileParent().
 */
final readonly class GraphReconcile
{
    /**
     * @param list<string> $stepTitles       step runs created (applied) or
     *                                       to create (preview)
     * @param list<int>    $dispatchedRunIds child run ids whose first turn
     *                                       message was dispatched — empty
     *                                       in preview; callers use it to
     *                                       avoid re-dispatching the same
     *                                       children again in one sweep
     */
    public function __construct(
        public int $parentId,
        public bool $applied,
        public array $stepTitles,
        public bool $finalConsumer,
        public ?RunStatus $settled,
        public array $dispatchedRunIds = [],
    ) {
    }
}
