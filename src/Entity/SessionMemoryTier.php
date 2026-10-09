<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where a note sits in the two-tier store (docs/design/SESSION_TASKS.md §3.2).
 *
 * - **hot** — injected into every request, bounded by TASKLOOM_SESSION_HOT.
 *   Writes go to hot; ageing demotes the oldest unpinned notes to cold.
 * - **cold** — retained, not injected, shown in the UI, promotable. Bounded
 *   by TASKLOOM_SESSION_COLD; the oldest cold note is dropped on overflow.
 *
 * Null on the objective row: the objective is not in the tier system — it is
 * always injected and is never aged or dropped.
 */
enum SessionMemoryTier: string
{
    /** Injected into every request. */
    case Hot = 'hot';

    /** Retained, not injected. */
    case Cold = 'cold';
}
