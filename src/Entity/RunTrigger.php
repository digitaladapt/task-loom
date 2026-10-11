<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What launched a run (SPEC §14.3).
 *
 * `manual` — a human or an agent hit a trigger: Run now in the admin UI,
 * `app:run:now` on the console.
 * `scheduled` — the scheduler tick fired the task's owed occurrence.
 * `continuation` — the session engine launched the next slice: the previous
 * slice declared a `milestone`, or the requeue sweep found one owed
 * (docs/design/SESSION_TASKS.md §8). A session's first slice carries
 * whichever trigger the operator used — `manual`, for Run now.
 *
 * Recorded so "why did this run at 3am?" has an answer in the ledger
 * without inferring from timestamps. Child runs of a step graph keep
 * `manual`; only the top-level run of a launch carries the trigger.
 */
enum RunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Continuation = 'continuation';
}
