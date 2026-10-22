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
 *  - `owed`     — enabled, nothing in flight, and the last slice settled
 *                 owing more work: a declared `milestone`, or — under the
 *                 `continue` policy (docs/design/SESSION_ENDINGS.md §4) — a
 *                 succeeded slice with no declaration. The next slice is due;
 *                 launched at settlement, and the requeue sweep re-derives it
 *                 after a lost dispatch.
 *  - `retrying` — enabled, nothing in flight, and the last slice failed in a
 *                 way that is worth another attempt (a provider 500, a
 *                 connection reset, a first-token timeout). The next slice is
 *                 owed on a backoff clock; it is NOT waiting for a human, and
 *                 the sweep launches it when the delay elapses
 *                 ({@see SessionRetry}). Distinct from `owed` because the
 *                 launch is not yet due, and distinct from `stopped` because
 *                 nobody needs to intervene. When the retry budget or its age
 *                 ceiling is spent, this derives `stopped` again and the
 *                 alarm fires.
 *  - `complete` — the last declaration was `done` (terminal);
 *  - `blocked`  — the last declaration was `blocked`; needs the operator;
 *  - `stopped`  — the last slice settled without a declaration in a way the
 *                 policy does NOT continue (an exhausted slice budget, a
 *                 failed turn that is not retryable, a tripped breaker, an
 *                 empty message, an undeclared streak that hit its bound, or
 *                 a retry streak that spent its budget) — loud, and it waits
 *                 for a human. This is the state that raises the container
 *                 alarm ({@see SessionAlarm});
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
    case Retrying = 'retrying';
    case Complete = 'complete';
    case Blocked = 'blocked';
    case Stopped = 'stopped';
    case Paused = 'paused';
}
