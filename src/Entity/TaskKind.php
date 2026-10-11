<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A task's kind — SPEC §9. Two kinds, one engine: a `run` completes in one
 * loop pass; a `session` keeps working as a series of slices
 * (docs/design/SESSION_TASKS.md §8).
 */
enum TaskKind: string
{
    /** One loop pass: completes with a justified completion declaration. */
    case Run = 'run';

    /**
     * Long-lived work that continues in slices — bounded runs, each settled
     * by a typed declaration (`session_declare`); a milestone launches the
     * next slice in the same commit.
     */
    case Session = 'session';
}
