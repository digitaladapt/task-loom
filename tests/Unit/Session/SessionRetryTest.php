<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Session\SessionRetry;
use PHPUnit\Framework\TestCase;

/**
 * The retry constitution (docs/design/SESSION_ENDINGS.md §9):
 * retry the environment being briefly unavailable, with backoff, and stop
 * once it is clear the problem is not transient after all.
 *
 * The assertions that matter are the negative ones. "A provider blip is
 * retried" is the feature; "a spent budget is NOT retried" and "an
 * unclassified failure is NOT retried" are what keep the retry from becoming
 * the infinite loop the design is trying to eliminate. Both ceilings are
 * tested from the exact boundary, because an off-by-one here is either a
 * session that stops one attempt early or one that never stops.
 */
final class SessionRetryTest extends TestCase
{
    public function testTheDefaultCeilingsAreSane(): void
    {
        $retry = new SessionRetry();

        self::assertSame(5, $retry->maxAttempts);
        self::assertSame(30, $retry->baseDelaySeconds);
        self::assertSame(900, $retry->maxDelaySeconds);
        self::assertSame(3600, $retry->maxAgeSeconds);
    }

    public function testOnlyTransientClassesAreAdmitted(): void
    {
        $retry = new SessionRetry();

        // The environment being briefly unavailable.
        foreach ([
            ErrorClass::ServerError,
            ErrorClass::ServerTimeout,
            ErrorClass::LlmError,
            ErrorClass::LlmFirstTokenTimeout,
            ErrorClass::LlmSilenceTimeout,
        ] as $class) {
            self::assertTrue($retry->admits($class), $class->value.' must be retried');
        }
    }

    /**
     * Terminal by construction, or the model misbehaving: waiting does not
     * help, and for the stream cuts it would re-run the misbehaviour.
     */
    public function testTerminalAndMisbehaviourClassesAreRefused(): void
    {
        $retry = new SessionRetry();

        foreach ([
            ErrorClass::BudgetExceeded,
            ErrorClass::ContextExhausted,
            ErrorClass::LlmLoopDetected,
            ErrorClass::LlmDurationExceeded,
            ErrorClass::LlmMalformedResponse,
            ErrorClass::InvalidArguments,
            ErrorClass::ToolNotFound,
            ErrorClass::Unknown,
        ] as $class) {
            self::assertFalse($retry->admits($class), $class->value.' must NOT be retried');
        }
    }

    /**
     * Null is "nothing in the taxonomy described it". Assuming that clears is
     * how a loop of indistinguishable failures is born.
     */
    public function testAnUnclassifiedFailureIsNotRetried(): void
    {
        self::assertFalse((new SessionRetry())->admits(null));
    }

    public function testBackoffDoublesAndThenSaturates(): void
    {
        $retry = new SessionRetry(baseDelaySeconds: 30, maxDelaySeconds: 900);

        self::assertSame(30, $retry->delaySeconds(1));
        self::assertSame(60, $retry->delaySeconds(2));
        self::assertSame(120, $retry->delaySeconds(3));
        self::assertSame(240, $retry->delaySeconds(4));
        self::assertSame(480, $retry->delaySeconds(5));

        // Past the cap it stays put rather than growing without bound.
        self::assertSame(900, $retry->delaySeconds(6));
        self::assertSame(900, $retry->delaySeconds(50));
    }

    public function testAttemptCountsFromZeroAndOneBothMeanTheBaseDelay(): void
    {
        $retry = new SessionRetry(baseDelaySeconds: 30);

        // Defensive: the arithmetic must not produce a negative exponent.
        self::assertSame(30, $retry->delaySeconds(0));
    }

    /**
     * The boundary. `maxAttempts` counts the attempts themselves, not the
     * retries after the first: a budget of 3 means "three tries at this, then
     * stop", so the third failure ends the streak and the second does not.
     * Getting this off by one is either a session that gives up a try early or
     * one that never gives up.
     */
    public function testAStreakStopsExactlyWhenAttemptsAreSpent(): void
    {
        $retry = new SessionRetry(maxAttempts: 3, baseDelaySeconds: 10);
        $now = 1_000_000;

        $state = $retry->assess($this->failures(2, $now, ErrorClass::ServerError), $now);

        self::assertNotNull($state);
        self::assertSame(2, $state->attempts);
        self::assertFalse($state->exhausted(), 'two of three attempts spent: still retrying');

        $state = $retry->assess($this->failures(3, $now, ErrorClass::ServerError), $now);

        self::assertNotNull($state);
        self::assertSame(3, $state->attempts);
        self::assertTrue($state->exhausted(), 'the budget of 3 is spent, so the retry gives up');
        self::assertNotNull($state->exhaustedWhy);
    }

    /**
     * The other ceiling, and the one that matters for a provider outage that
     * lasts: attempts may still be left, but an hour of failing is enough.
     */
    public function testAnOldStreakStopsEvenWithAttemptsLeft(): void
    {
        $retry = new SessionRetry(maxAttempts: 10, baseDelaySeconds: 10, maxAgeSeconds: 600);
        $now = 1_000_000;

        // Newest first. Oldest failure 600s ago: exactly at the ceiling.
        $rows = [
            $this->failedRun($now - 10, ErrorClass::ServerError),
            $this->failedRun($now - 300, ErrorClass::ServerError),
            $this->failedRun($now - 600, ErrorClass::ServerError),
        ];

        $state = $retry->assess($rows, $now);

        self::assertNotNull($state);
        self::assertSame(3, $state->attempts);
        self::assertTrue($state->exhausted(), 'the streak is as old as the ceiling, so it is spent');
    }

    public function testAYoungStreakWithAttemptsLeftIsNotExhausted(): void
    {
        $retry = new SessionRetry(maxAttempts: 10, baseDelaySeconds: 10, maxAgeSeconds: 600);
        $now = 1_000_000;

        $rows = [
            $this->failedRun($now - 10, ErrorClass::ServerError),
            $this->failedRun($now - 100, ErrorClass::ServerError),
        ];

        $state = $retry->assess($rows, $now);

        self::assertNotNull($state);
        self::assertFalse($state->exhausted());
        self::assertSame($now - 100, $state->streakStartedAt);
    }

    /**
     * A non-retryable failure on top means the session is not retrying at
     * all — the streak ended with something terminal.
     */
    public function testANewestTerminalFailureBreaksTheStreak(): void
    {
        $retry = new SessionRetry();
        $now = 1_000_000;

        $rows = [
            $this->failedRun($now - 10, ErrorClass::BudgetExceeded),
            $this->failedRun($now - 20, ErrorClass::ServerError),
        ];

        self::assertNull($retry->assess($rows, $now));
    }

    /**
     * A success on top means the session made progress — the old failures are
     * history, not a streak, and must not exhaust a budget.
     */
    public function testASuccessBreaksTheStreak(): void
    {
        $retry = new SessionRetry();
        $now = 1_000_000;

        $succeeded = new Run($this->fakeTask());
        $succeeded->markSucceeded();

        $rows = [$succeeded, $this->failedRun($now - 20, ErrorClass::ServerError)];

        self::assertNull($retry->assess($rows, $now));
    }

    public function testTheFirstRetryWaitsTheBaseDelay(): void
    {
        $retry = new SessionRetry(baseDelaySeconds: 30);
        $now = 1_000_000;

        $state = $retry->assess([$this->failedRun($now, ErrorClass::ServerError)], $now);

        self::assertNotNull($state);
        self::assertSame(1, $state->attempts);
        self::assertSame($now + 30, $state->nextAttemptAt);
        self::assertTrue($state->waiting($now), 'immediately after the failure it is not yet due');
        self::assertFalse($state->due($now));
        self::assertSame(30, $state->secondsUntilDue($now));

        // Once the delay has elapsed it is due, and no longer waiting.
        self::assertTrue($state->due($now + 30));
        self::assertFalse($state->waiting($now + 30));
        self::assertSame(0, $state->secondsUntilDue($now + 30));
    }

    public function testTheSecondRetryWaitsTwiceAsLong(): void
    {
        $retry = new SessionRetry(baseDelaySeconds: 30);
        $now = 1_000_000;

        $state = $retry->assess($this->failures(2, $now, ErrorClass::ServerError), $now);

        self::assertNotNull($state);
        self::assertSame(60, $state->nextAttemptAt - $now);
    }

    public function testTheDelayIsComputedFromTheFailureThatStartedTheWait(): void
    {
        $retry = new SessionRetry(baseDelaySeconds: 30);
        $failedAt = 1_000_000;

        $run = $this->failedRun($failedAt, ErrorClass::ServerError);

        self::assertSame($failedAt + 30, $retry->nextAttemptAt($run, 1));
        self::assertSame($failedAt + 60, $retry->nextAttemptAt($run, 2));
        self::assertSame($failedAt + 120, $retry->nextAttemptAt($run, 3));
    }

    public function testConstructionRejectsNonsenseCeilings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionRetry(maxAttempts: 0);
    }

    public function testConstructionRejectsANegativeBaseDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionRetry(baseDelaySeconds: -1);
    }

    public function testConstructionRejectsZeroAge(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionRetry(maxAgeSeconds: 0);
    }

    /**
     * Newest-first, `$count` consecutive failures ending at `$newestAt`.
     *
     * @return list<Run>
     */
    private function failures(int $count, int $newestAt, ErrorClass $class): array
    {
        $rows = [];

        for ($i = 0; $i < $count; ++$i) {
            $rows[] = $this->failedRun($newestAt - $i, $class);
        }

        return $rows;
    }

    private function failedRun(int $finishedAt, ErrorClass $class): Run
    {
        $run = new Run($this->fakeTask());
        $run->markFailed($class);

        // The clock is the whole subject of the backoff, so pin it rather than
        // letting markFailed stamp "now".
        $property = new \ReflectionProperty(Run::class, 'finishedAt');
        $property->setValue($run, new \DateTimeImmutable('@'.$finishedAt));

        return $run;
    }

    private function fakeTask(): \App\Entity\Task
    {
        $task = $this->createStub(\App\Entity\Task::class);

        return $task;
    }
}
