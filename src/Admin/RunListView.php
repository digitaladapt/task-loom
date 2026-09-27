<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Run;

/**
 * The run list page's model (SPEC §8): the scheduler view — who holds the
 * LLM slot, who is queued, which step graphs are in flight — above the
 * recent-run history.
 */
final readonly class RunListView
{
    /**
     * @param list<array{run: Run, lane: string, claimAge: int, stale: bool}> $slotHolders runs with a live
     *                                                                                     execution claim,
     *                                                                                     and the lane they're
     *                                                                                     on (llm / tools)
     * @param list<array{run: Run, label: string}>                            $waiting     active runs without a
     *                                                                                     claim: queued (FIFO) or
     *                                                                                     waiting for a lane
     * @param list<array{run: Run, done: int, total: int}>                    $graphs      active parents
     * @param list<Run>                                                       $recent
     */
    public function __construct(
        public array $slotHolders,
        public array $waiting,
        public array $graphs,
        public array $recent,
    ) {
    }
}
