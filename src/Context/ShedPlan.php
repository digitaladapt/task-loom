<?php

declare(strict_types=1);

namespace App\Context;

/**
 * A caller-owned shedding plan for one fit ({@see ContextWindow::buildMessages()}).
 *
 * The context window's default is stateless: it is the newest N exchanges, and
 * once more than N exist its front advances by one exchange every turn — the
 * per-turn re-prefill breaker B2 names (docs/design/KV_CACHE.md §4). Trimming
 * cannot fix that from inside a single call, because "did the front move?" is a
 * fact about the *previous* request, and the window sees only this one.
 *
 * So the state lives with whoever owns a run's turn-to-turn progress — the
 * checkpoint ({@see \App\RunEngine\LoopState::$contextBase}) — and is handed
 * back in here. `base` is the sticky front: the engine advances it only when a
 * shed forces it, so between sheds the front stands still and the prefix the
 * server already prefilled is reused. `batch` says to shed to the headroom
 * target rather than to "just fits", so one re-prefill buys a long stable
 * stretch instead of a trim next turn (§6.2).
 *
 * Passing null instead of a plan keeps the window's own stateless count bound,
 * which is what the unit tests and any non-engine caller get.
 */
final readonly class ShedPlan
{
    /**
     * @param ?int $base  the sticky front index into the exchange list, or null for 0 — the
     *                    retaining caller is expected to advance it to `count - kept`
     *                    after a shed, which is where the retained window now begins
     * @param bool $batch shed a whole batch to the headroom target when the budget forces
     *                    anything out, rather than stopping at the newest exchange that fits
     */
    public function __construct(
        public ?int $base = null,
        public bool $batch = false,
    ) {
    }
}
