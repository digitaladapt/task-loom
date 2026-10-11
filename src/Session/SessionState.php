<?php

declare(strict_types=1);

namespace App\Session;

/**
 * A session's derived state (docs/design/SESSION_TASKS.md §8, build order
 * step 4) — a function of committed rows only: the task's `enabled` flag,
 * its top-level runs (its slices), and their declarations. Never stored:
 * a stored copy would be a second source of truth about progress, which is
 * exactly the kind of thing this project refuses.
 *
 *  - `fresh`    — enabled, no slice has run yet. The operator's Run now
 *                 starts the first slice; the boot sweep never starts a
 *                 session on its own (the note's start action surfaces as
 *                 Enable + Run now until the session UI folds them into one
 *                 control, step 6).
 *  - `working`  — enabled, a slice is in flight;
 *  - `owed`     — enabled, nothing in flight, and the last slice declared a
 *                 `milestone`: the next slice is due. Launched at settlement;
 *                 the requeue sweep re-derives it after a lost dispatch.
 *  - `complete` — the last declaration was `done` (terminal);
 *  - `blocked`  — the last declaration was `blocked`; needs the operator;
 *  - `stopped`  — the last slice settled without a declaration (an
 *                 exhausted slice budget, a failed turn, a tripped breaker,
 *                 a plain message) — loud, and it waits for a human;
 *  - `paused`   — the task is disabled; the operator's switch. Derived
 *                 first: nothing launches while it is off.
 *
 * The supervisor (the requeue sweep) and, later, the UI read this one
 * derivation; nothing branches on kind-state anywhere else.
 */
enum SessionState: string
{
    case Fresh = 'fresh';
    case Working = 'working';
    case Owed = 'owed';
    case Complete = 'complete';
    case Blocked = 'blocked';
    case Stopped = 'stopped';
    case Paused = 'paused';
}
