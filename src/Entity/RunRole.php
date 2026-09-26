<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What a Run is within the step model (SPEC §13.3).
 *
 * Under run-per-step, one task run is a *graph* of runs: a parent that
 * aggregates, one child run per step, and one final-consumer child run for
 * the task's own brief/toolbox. A task with zero steps still produces a
 * single Standalone run — exactly the v1 shape, unchanged.
 */
enum RunRole: string
{
    /**
     * An ordinary v1 run: a step-less task's whole execution. The zero-step
     * path is byte-identical to v1 (SPEC §13.1) — this role is what makes
     * "no step model" representable without a special case at read time.
     */
    case Standalone = 'standalone';

    /**
     * The aggregator of a stepped task's run graph — the unit the admin UI
     * shows as "the run of the task" (SPEC §13.6). A parent executes no
     * turns of its own: it carries no toolbox, no checkpoint, and no claim;
     * its status is derived from its children's outcomes (§13.3, §13.5).
     */
    case Parent = 'parent';

    /**
     * A child run executing one step of the task's graph: the step's brief
     * and toolbox are its constitution. Its completion artifact is the
     * step's output (SPEC §13.4).
     */
    case Step = 'step';

    /**
     * The child run executing the task's own brief/toolbox — the final
     * consumer of the step graph (SPEC §13.1). Created only once every step
     * has succeeded; its outcome settles the parent.
     */
    case FinalConsumer = 'final_consumer';
}
