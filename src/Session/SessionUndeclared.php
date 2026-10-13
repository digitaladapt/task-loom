<?php

declare(strict_types=1);

namespace App\Session;

/**
 * What a session does when a slice settles WITHOUT a declaration
 * (docs/design/SESSION_TASKS.md §8; docs/design/SESSION_ENDINGS.md §2).
 *
 * - `stop` — the default, and the behavior every deployment had before the
 *   knob: the slice settles `incomplete`, the session derives `stopped`, and
 *   a human looks. Loud; no retry.
 * - `continue` — an undeclared ending is a legitimate settlement: the slice
 *   succeeds with the message as its artifact, and the next slice launches
 *   in the same commit, exactly as a `milestone` does. Bounded by
 *   {@see SessionEndings::$maxUndeclared}: that many consecutive undeclared
 *   settlements stop the session for a human — a counted streak, not a
 *   judgment (the circuit breaker's shape).
 *
 * A settlement that actually went wrong — an exhausted slice budget, a
 * failed turn, a tripped breaker — is not an "undeclared ending" under
 * either value: its status is not `succeeded`, and the session always stops
 * for a human (§8's "no automatic slice retry" stays true for everything
 * that broke).
 */
enum SessionUndeclared: string
{
    case Stop = 'stop';
    case Continue = 'continue';
}
