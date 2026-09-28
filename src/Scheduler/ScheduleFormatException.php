<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * A task's schedule is not a valid cron expression (SPEC §14).
 *
 * Refused at task create/update — and again at enable/approve, the
 * enforcement gate — so an invalid schedule never fires. Carries a
 * human-and-agent-readable diagnosis naming the offending expression.
 */
final class ScheduleFormatException extends \RuntimeException
{
    public function __construct(string $schedule, string $reason)
    {
        parent::__construct(\sprintf('Invalid schedule "%s": %s', $schedule, $reason));
    }
}
