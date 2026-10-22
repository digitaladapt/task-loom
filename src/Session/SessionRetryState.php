<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\ErrorClass;

/**
 * A session's retry condition, derived from committed rows — the answer to
 * "the newest slice failed transiently; is another attempt due, still to
 * come, or spent?" (docs/design/SESSION_ENDINGS.md §9.1).
 *
 * Derived, never stored, for the same reason {@see SessionState} is: a stored
 * copy would be a second source of truth about progress, and the one thing
 * this project refuses. Everything this needs is already on the failed slice —
 * its `finishedAt` and its `error_class` — so backoff costs no column and no
 * migration, and cannot go stale against the rows it describes.
 *
 * The four readings, and what each means for the sweep:
 *
 *  - **due** — the delay has elapsed; the next slice should launch now. This
 *    is `owed` with a clock attached.
 *  - **waiting** — a real slice is still owed, but not yet. The sweep
 *    converges to nothing and checks again next pass. This is the state the
 *    whole feature exists to make safe: the session is NOT `stopped`, NOT
 *    `blocked`, and NOT unscheduled, because the heartbeat re-derives it every
 *    pass and the deadline is arithmetic on a live row.
 *  - **exhausted** — the attempt budget or the age ceiling is spent. The
 *    session derives `stopped` and the alarm fires: a retry loop that cannot
 *    terminate is the same defect as idling forever, a session that looks
 *    alive while doing nothing.
 *  - null (the model's absence) — the newest slice is not a retryable
 *    failure, which is the ordinary case and costs nothing.
 */
final readonly class SessionRetryState
{
    /**
     * @param int         $attempts        consecutive retryable failures ending at the newest slice
     * @param int         $nextAttemptAt   when the next attempt becomes due (unix seconds)
     * @param int         $streakStartedAt when the streak's first failure settled
     * @param ErrorClass  $class           the newest failure's class (what the operator is told)
     * @param string|null $exhaustedWhy    null while the streak may continue; else why it may not
     */
    public function __construct(
        public int $attempts,
        public int $nextAttemptAt,
        public int $streakStartedAt,
        public ErrorClass $class,
        public ?string $exhaustedWhy = null,
    ) {
    }

    /** The budget or the age ceiling is spent: stop, and raise the alarm. */
    public function exhausted(): bool
    {
        return null !== $this->exhaustedWhy;
    }

    /** The next attempt may launch now. */
    public function due(int $now): bool
    {
        return !$this->exhausted() && $now >= $this->nextAttemptAt;
    }

    /** Owed, but not yet — the sweep waits for the clock, not for a human. */
    public function waiting(int $now): bool
    {
        return !$this->exhausted() && $now < $this->nextAttemptAt;
    }

    /** Seconds until the next attempt (0 when due or spent). */
    public function secondsUntilDue(int $now): int
    {
        return max(0, $this->nextAttemptAt - $now);
    }

    /**
     * One line for the operator's page, the log, and the alarm.
     */
    public function describe(int $now): string
    {
        if (null !== $this->exhaustedWhy) {
            return \sprintf('retries exhausted after %d attempt(s) on %s — %s', $this->attempts, $this->class->value, $this->exhaustedWhy);
        }

        if ($this->waiting($now)) {
            return \sprintf('retrying after %s (attempt %d) — next in %ds', $this->class->value, $this->attempts + 1, $this->secondsUntilDue($now));
        }

        return \sprintf('retrying after %s (attempt %d) — due now', $this->class->value, $this->attempts + 1);
    }
}
