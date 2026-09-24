<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * Mutable loop state for one run: budgets, the accumulating exchange
 * window, circuit-breaker failure streaks, the compiled prompt head, and
 * the in-flight tool turn.
 *
 * Persisted (as JSON) in Run.checkpoint at every checkpoint boundary
 * (SPEC §5.5): the delivery unit of the async engine is one LLM turn or one
 * tool turn, and each turn is handed a run whose full state reloads from
 * the row — the next worker is a stranger (SPEC §6). Nothing here may
 * require live objects: entities, services, closures stay out.
 *
 * The prompt head is stored too, not recompiled per turn: it contains the
 * grounding block (date/time), and the run's constitution must not drift
 * across turns of the same run (SPEC §4.1) — a run straddling midnight
 * still finishes under the date it started with.
 *
 * `exchanges` entries are the ContextWindow exchange shape:
 * {assistant: {content, toolCalls}, toolResults: list<{toolCallId, content}>}.
 *
 * @internal
 */
final class LoopState
{
    /**
     * @param list<array<string, mixed>>               $exchanges
     * @param array<string, int>                       $failureCounts
     * @param array{system: string, user: string}|null $promptHead
     */
    public function __construct(
        public int $step = 0,
        public int $stepBudget = 50,
        public int $toolRetries = 2,
        public int $circuitBreakerThreshold = 3,
        public array $exchanges = [],
        public array $failureCounts = [],
        public ?array $promptHead = null,
        public ?PendingToolTurn $pendingToolTurn = null,
    ) {
    }

    /**
     * Rebuild from Run.checkpoint. The stored budgets win over the currently
     * configured ones: a run finishes under the budgets it started with,
     * even if the knobs moved in between (the run's constitution does not
     * move, SPEC §4.1 — same reasoning as the frozen toolbox).
     *
     * @param array<string, mixed>|null $checkpoint
     */
    public static function fromCheckpoint(?array $checkpoint, int $stepBudget, int $toolRetries, int $circuitBreakerThreshold): self
    {
        if (null === $checkpoint) {
            return new self(
                stepBudget: $stepBudget,
                toolRetries: $toolRetries,
                circuitBreakerThreshold: $circuitBreakerThreshold,
            );
        }

        $exchanges = [];
        $rawExchanges = $checkpoint['exchanges'] ?? [];
        if (\is_array($rawExchanges)) {
            foreach ($rawExchanges as $exchange) {
                if (\is_array($exchange)) {
                    $exchanges[] = $exchange;
                }
            }
        }

        $failureCounts = [];
        $rawCounts = $checkpoint['failureCounts'] ?? [];
        if (\is_array($rawCounts)) {
            foreach ($rawCounts as $key => $count) {
                $failureCounts[(string) $key] = (int) $count;
            }
        }

        $promptHead = null;
        $rawHead = $checkpoint['promptHead'] ?? null;
        if (\is_array($rawHead) && isset($rawHead['system'], $rawHead['user'])) {
            $promptHead = [
                'system' => (string) $rawHead['system'],
                'user' => (string) $rawHead['user'],
            ];
        }

        $pending = null;
        $rawPending = $checkpoint['pendingToolTurn'] ?? null;
        if (\is_array($rawPending)) {
            $pending = PendingToolTurn::fromArray($rawPending);
        }

        return new self(
            step: (int) ($checkpoint['step'] ?? 0),
            stepBudget: (int) ($checkpoint['stepBudget'] ?? $stepBudget),
            toolRetries: (int) ($checkpoint['toolRetries'] ?? $toolRetries),
            circuitBreakerThreshold: (int) ($checkpoint['circuitBreakerThreshold'] ?? $circuitBreakerThreshold),
            exchanges: $exchanges,
            failureCounts: $failureCounts,
            promptHead: $promptHead,
            pendingToolTurn: $pending,
        );
    }

    /**
     * The checkpoint payload: everything the next turn needs, and nothing
     * that only makes sense on this worker.
     *
     * @return array<string, mixed>
     */
    public function toCheckpoint(): array
    {
        return [
            'step' => $this->step,
            'stepBudget' => $this->stepBudget,
            'toolRetries' => $this->toolRetries,
            'circuitBreakerThreshold' => $this->circuitBreakerThreshold,
            'exchanges' => $this->exchanges,
            'failureCounts' => $this->failureCounts,
            'promptHead' => $this->promptHead,
            'pendingToolTurn' => $this->pendingToolTurn?->toArray(),
        ];
    }
}
