<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What launched a run (SPEC §14.3).
 *
 * `manual` — a human or an agent hit a trigger: Run now in the admin UI,
 * `app:run:now` on the console.
 * `scheduled` — the scheduler tick fired the task's owed occurrence.
 *
 * Recorded so "why did this run at 3am?" has an answer in the ledger
 * without inferring from timestamps. Child runs of a step graph keep
 * `manual`; only the top-level run of a launch carries the trigger.
 */
enum RunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
}
