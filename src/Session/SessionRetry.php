<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunStatus;

/**
 * The retry constitution for a stalled session slice: which failures are worth
 * another attempt, how long to wait, and when to stop waiting and raise the
 * alarm (docs/design/SESSION_ENDINGS.md §9.1).
 *
 * The pre-existing rule is `stopped`: a slice that failed "waits for a human".
 * That is right for what it was written for — an unbuildable constitution, a
 * model that cannot follow the declaration contract, an exhausted budget —
 * because none of those get better by waiting. It is wrong for the failure
 * this class exists for: the *environment* being briefly unavailable. A
 * provider 500, a connection reset, a first-token timeout, a 429. Retrying
 * those is not optimism, it is the only correct response; stopping a session
 * because a provider blipped is a self-inflicted outage, and staying stopped
 * until a human notices is the failure mode that made this worth building.
 *
 * ## Bounded by construction
 *
 * A retry loop that cannot terminate is the same defect as idling forever.
 * Two independent ceilings are enforced HERE, in the derivation, so no launch
 * path can bypass them: the attempt count, and the age of the streak's first
 * failure. Once either is spent the session derives `stopped` and
 * {@see SessionAlarm} raises the red light — the retry did its job and
 * the problem is not transient after all.
 *
 * ## An allowlist, not a denylist
 *
 * `budget_exceeded` and `context_exhausted` are terminal by construction:
 * retrying re-hits the same wall with backoff and leaves the session no
 * better off than stopping at once. So only classes describing a transient
 * transport or provider condition are admitted, and the list is an allowlist
 * for the reason the taxonomy is fixed at all — a new class must be
 * deliberately admitted here, never silently inherited.
 *
 * Null (an unclassified failure) is NOT retried: if nothing in the taxonomy
 * described it, assuming it will clear is how a loop of indistinguishable
 * failures is born.
 *
 * Stream cuts are not retried either. `llm_loop_detected` and
 * `llm_duration_exceeded` mean the guard fired because the model was
 * misbehaving; retrying re-runs the misbehaviour. Those stop for a human.
 * The two cut classes that DO describe wedged infrastructure —
 * first-token and silence — are admitted, because that is the server, not
 * the model.
 */
final readonly class SessionRetry
{
    /** Transient transport/provider failures only; see the class note. */
    public const array RETRYABLE = [
        ErrorClass::ServerError,
        ErrorClass::ServerTimeout,
        ErrorClass::LlmError,
        ErrorClass::LlmFirstTokenTimeout,
        ErrorClass::LlmSilenceTimeout,
    ];

    /**
     * @param int $maxAttempts      consecutive retryable failures before the session stops
     * @param int $baseDelaySeconds the first backoff; doubles per attempt
     * @param int $maxDelaySeconds  the backoff ceiling
     * @param int $maxAgeSeconds    past this since the streak's first failure, stop
     */
    public function __construct(
        public int $maxAttempts = 5,
        public int $baseDelaySeconds = 30,
        public int $maxDelaySeconds = 900,
        public int $maxAgeSeconds = 3600,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('TASKLOOM_SESSION_RETRY_MAX_ATTEMPTS must be at least 1.');
        }

        if ($baseDelaySeconds < 1 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new \InvalidArgumentException('Session retry delays must be positive, and the ceiling must not be below the base delay.');
        }

        if ($maxAgeSeconds < 1) {
            throw new \InvalidArgumentException('TASKLOOM_SESSION_RETRY_MAX_AGE must be at least 1 second.');
        }
    }

    public function admits(?ErrorClass $class): bool
    {
        return null !== $class && \in_array($class, self::RETRYABLE, true);
    }

    /**
     * How long to wait before the attempt that follows a streak of this size:
     * `base * 2^(attempts-1)`, capped. With the defaults: 30s, 1m, 2m, 4m,
     * 8m — the ceiling is reached on the last attempt.
     */
    public function delaySeconds(int $attempts): int
    {
        $delay = $this->baseDelaySeconds * (2 ** max(0, $attempts - 1));

        return min($delay, $this->maxDelaySeconds);
    }

    /**
     * The retry condition of a session whose newest slice is the given one,
     * or null when that slice is not a retryable failure — the ordinary
     * case, and the one that costs nothing.
     *
     * `$newestFirst` is the session's slices newest-first (what
     * {@see \App\Repository\RunRepository::findForTask()} returns), and
     * `$newestFirst[0]` is the slice being judged. The streak is walked back
     * from it while it keeps seeing retryable failures: a succeeded slice, or
     * one that failed for a terminal class, ends the streak — it is
     * *consecutive* by definition, so a blip an hour ago does not spend this
     * streak's budget.
     *
     * @param list<Run> $newestFirst
     */
    public function assess(array $newestFirst, int $now): ?SessionRetryState
    {
        $newest = $newestFirst[0] ?? null;

        if (null === $newest || RunStatus::Failed !== $newest->getStatus() || !$this->admits($newest->getErrorClass())) {
            return null;
        }

        $attempts = 0;
        $oldestFinished = null;

        foreach ($newestFirst as $slice) {
            if (RunStatus::Failed !== $slice->getStatus() || !$this->admits($slice->getErrorClass())) {
                break;
            }

            ++$attempts;
            $oldestFinished = $slice->getFinishedAt();
        }

        // The streak's own clock starts at its OLDEST failure: that is the
        // instant the environment first went bad, and the age ceiling is a
        // bound on how long the session keeps hoping it clears.
        $streakStartedAt = ($oldestFinished ?? new \DateTimeImmutable())->getTimestamp();
        $attempts = max(1, $attempts);

        return new SessionRetryState(
            attempts: $attempts,
            nextAttemptAt: $this->nextAttemptAt($newest, $attempts),
            streakStartedAt: $streakStartedAt,
            class: $newest->getErrorClass(),
            exhaustedWhy: $this->exhaustion($attempts, $streakStartedAt, $now),
        );
    }

    /**
     * The instant the attempt following a streak of this size becomes due.
     * Anchored on the newest failure's `finishedAt`, which is already
     * persisted — this is why backoff needs no new column.
     */
    public function nextAttemptAt(Run $failed, int $attempts): int
    {
        $finished = $failed->getFinishedAt() ?? new \DateTimeImmutable();

        return $finished->getTimestamp() + $this->delaySeconds($attempts);
    }

    /**
     * Why the streak must end, or null while it may continue. Both ceilings
     * are checked against the state as it stands, so an operator who fixes
     * the environment and resumes the session starts a fresh streak — a
     * resume is a deliberate act and is entitled to its own budget.
     */
    private function exhaustion(int $attempts, int $streakStartedAt, int $now): ?string
    {
        if ($attempts >= $this->maxAttempts) {
            return \sprintf('the attempt budget of %d is spent', $this->maxAttempts);
        }

        if ($now - $streakStartedAt >= $this->maxAgeSeconds) {
            return \sprintf('the streak has been failing for more than %ds', $this->maxAgeSeconds);
        }

        return null;
    }
}
