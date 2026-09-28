<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Run;
use App\Entity\Task;

/**
 * What one scheduler tick did (SPEC §14) — reportable data, never just a
 * log line. The tick accumulates into it; the command prints it; tests
 * assert on it.
 *
 * Deliberately mutable (not readonly): the tick appends as it walks the
 * tasks, and a fresh instance per tick keeps the accumulation honest.
 */
final class TickResult
{
    /**
     * @param list<array{task: Task, run: Run}>                 $fired    tasks whose owed occurrence launched a run
     * @param list<array{task: Task, run: Run, reason: string}> $failed   occurrences whose launch failed at dispatch (recorded as failed runs)
     * @param list<array{task: Task, next: int}>                $armed    tasks armed for the first time (cursor set, no fire)
     * @param list<array{task: Task, reason: string}>           $skipped  due occurrences deliberately not fired (a previous run of the task is still active)
     * @param list<array{task: Task, reason: string}>           $deferred occurrences left owed for the next tick (the launch rolled back; retried, never skipped)
     */
    public function __construct(
        public array $fired = [],
        public array $failed = [],
        public array $armed = [],
        public array $skipped = [],
        public array $deferred = [],
    ) {
    }

    /**
     * Whether the tick reported anything an operator should look at —
     * a launch that failed or rolled back. Fired / armed / skipped are
     * the tick doing its job.
     */
    public function hasProblems(): bool
    {
        return [] !== $this->failed || [] !== $this->deferred;
    }

    public function isEmpty(): bool
    {
        return [] === $this->fired
            && [] === $this->failed
            && [] === $this->armed
            && [] === $this->skipped
            && [] === $this->deferred;
    }
}
