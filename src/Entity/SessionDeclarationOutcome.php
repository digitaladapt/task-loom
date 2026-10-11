<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A slice's typed declaration (docs/design/SESSION_TASKS.md §8) — the
 * go/no-go that ends a session slice, recorded on the run in
 * `run.declaration`.
 *
 * - `milestone` — progress was made and more remains; the harness launches
 *   the next slice in the same commit as the settlement.
 * - `done` — the session's goal is complete; the session is terminal.
 * - `blocked` — a human is needed; the slice settles for attention and no
 *   further slice launches until an operator resumes.
 *
 * The outcome is deliberately NOT a `RunStatus`. A milestone and a done
 * slice both settle `succeeded` — the declaration was justified, which is
 * what `succeeded` has always meant — and a blocked slice settles
 * `needs_attention`; the finer grain lives here, where the session's
 * derived state (§8) and the requeue sweep read it.
 */
enum SessionDeclarationOutcome: string
{
    case Milestone = 'milestone';
    case Done = 'done';
    case Blocked = 'blocked';
}
