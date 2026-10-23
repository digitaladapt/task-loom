<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Session\SessionEndings;
use App\Session\SessionUndeclared;

/**
 * Mutable loop state for one run: budgets, circuit-breaker failure streaks,
 * the compiled prompt head, and the in-flight tool turn.
 *
 * Persisted (as JSON) in Run.checkpoint at every checkpoint boundary
 * (SPEC §5.5): the delivery unit of the async engine is one LLM turn or one
 * tool turn, and each turn is handed a run whose full state reloads from
 * the row — the next worker is a stranger (SPEC §6). Nothing here may
 * require live objects: entities, services, closures stay out.
 *
 * ## The transcript is not in here (docs/design/THE_STREAM.md §2.1)
 *
 * This class used to carry `exchanges`, the accumulating window of every turn
 * the run had taken — which made the run's checkpoint the only copy of the
 * conversation, and made continuity across runs impossible by construction: a
 * new run built a new LoopState and started empty. The transcript now lives in
 * `stream_event` and is read back by query
 * ({@see \App\Stream\StreamWriter::latest()}), so a slice boundary is not a
 * memory boundary. The checkpoint keeps the counters and the head, and nothing
 * here needs to be big.
 *
 * The prompt head is still stored rather than recompiled per turn: it contains
 * the grounding block (date/time), and the run's constitution must not drift
 * across turns of the same run (SPEC §4.1) — a run straddling midnight
 * still finishes under the date it started with.
 *
 * `contextBase` and `contextBatch` are the run's sticky shedding state
 * (docs/design/KV_CACHE.md §6.2): the index the retained window's front stands
 * at until a trim forces it forward, and whether that trim sheds a whole batch
 * to the headroom target. They live here, not in the window, because "did the
 * front move?" is a fact about the previous request — the window sees only
 * this one — so the state has to travel with the run's progress in the
 * checkpoint. The index is into the stream's tail, counted the same way every
 * request counts it: position within the rows the window was given.
 *
 * @internal
 */
final class LoopState
{
    /**
     * @param array<string, int>                       $failureCounts
     * @param array{system: string, user: string}|null $promptHead
     */
    public function __construct(
        public int $step = 0,
        public int $stepBudget = 50,
        public int $toolRetries = 2,
        public int $circuitBreakerThreshold = 3,
        public array $failureCounts = [],
        public ?array $promptHead = null,
        public ?PendingToolTurn $pendingToolTurn = null,
        public SessionUndeclared $undeclared = SessionUndeclared::Stop,
        public int $maxUndeclared = SessionEndings::DEFAULT_MAX_UNDECLARED,
        public ?int $contextBase = null,
        public bool $contextBatch = false,
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
            failureCounts: $failureCounts,
            promptHead: $promptHead,
            pendingToolTurn: $pending,
            // The undeclared policy is constitution, frozen per slice like
            // the budgets. A checkpoint that predates the policy (or an
            // ordinary run) falls back to the conservative built-in: stop.
            undeclared: \is_string($checkpoint['undeclared'] ?? null)
                ? (SessionUndeclared::tryFrom($checkpoint['undeclared']) ?? SessionUndeclared::Stop)
                : SessionUndeclared::Stop,
            maxUndeclared: (int) ($checkpoint['maxUndeclared'] ?? SessionEndings::DEFAULT_MAX_UNDECLARED),
            // Null when unset, not 0: a run whose checkpoint predates the
            // sticky base keeps the window's own stateless bound until its
            // first forced trim, which is the old behavior exactly.
            contextBase: \is_int($checkpoint['contextBase'] ?? null) ? $checkpoint['contextBase'] : null,
            contextBatch: (bool) ($checkpoint['contextBatch'] ?? false),
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
            'failureCounts' => $this->failureCounts,
            'promptHead' => $this->promptHead,
            'pendingToolTurn' => $this->pendingToolTurn?->toArray(),
            'undeclared' => $this->undeclared->value,
            'maxUndeclared' => $this->maxUndeclared,
            'contextBase' => $this->contextBase,
            'contextBatch' => $this->contextBatch,
        ];
    }
}
