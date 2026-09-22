<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * Mutable loop state for one run: budgets, the accumulating exchange
 * window, and circuit-breaker failure streaks.
 *
 * @internal
 */
final class LoopState
{
    /**
     * @param list<array<string, mixed>> $exchanges
     * @param array<string, int>         $failureCounts
     */
    public function __construct(
        public int $step = 0,
        public int $stepBudget = 50,
        public int $toolRetries = 2,
        public int $circuitBreakerThreshold = 3,
        public array $exchanges = [],
        public array $failureCounts = [],
    ) {
    }
}
