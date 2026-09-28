<?php

declare(strict_types=1);

namespace App\Scheduler;

use Cron\CronExpression;

/**
 * The cron-expression side of scheduling (SPEC §14): validation,
 * next-occurrence computation, and timezone resolution — evaluated in the
 * deployment timezone.
 *
 * A task's `schedule` column stores the expression as authored; this
 * class is the only place that interprets it. It is deliberately
 * stateless — the timezone is a parameter, not a property — so the same
 * instance serves the enable gate, the tick, and the UI.
 *
 * Timezone handling, precisely: a schedule means "at this wall-clock time
 * in the deployment's timezone" (TASKLOOM_TIMEZONE). There is no silent
 * default: an 08:00 schedule quietly running at 08:00 UTC for a Chicago
 * operator is exactly the class of quiet wrongness this project refuses,
 * so the variable is part of the app's required env contract (the
 * container's boot lint names it when missing). nextAfter() computes in
 * that timezone and returns the instant in it; callers that persist the
 * cursor store `getTimestamp()` — an unambiguous Unix instant, immune to
 * the offset-stripping SQLite does to datetime columns.
 */
final readonly class ScheduleExpression
{
    /**
     * Valid — or absent — throws otherwise. Null and blank ('' or
     * whitespace) both mean "no schedule": a manual task, exactly as v1.
     */
    public function assertValid(?string $schedule): void
    {
        $normalized = self::normalize($schedule);
        if (null === $normalized) {
            return;
        }

        if (!CronExpression::isValidExpression($normalized)) {
            throw new ScheduleFormatException($normalized, 'not a valid cron expression. Expected five fields — minute hour day-of-month month day-of-week (e.g. "0 8 * * *" for 08:00 daily; "*/15 * * * *" for every 15 minutes) — or an @alias such as @daily or @hourly.');
        }
    }

    /**
     * The next occurrence strictly after $after, as a wall-clock instant
     * in $timezone (the deployment timezone). Cron granularity is one
     * minute; seconds are never produced.
     *
     * @throws ScheduleFormatException when the expression is invalid
     */
    public function nextAfter(?string $schedule, \DateTimeImmutable $after, string $timezone): \DateTimeImmutable
    {
        $normalized = self::normalize($schedule) ?? throw new ScheduleFormatException('(none)', 'no schedule is set.');

        try {
            $cron = new CronExpression($normalized);
            $next = $cron->getNextRunDate($after->setTimezone(new \DateTimeZone($timezone)), 0, false, $timezone);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The library's own failures (an invalid expression reaching
            // here — hand-edited data; an iteration-cap overrun) are
            // normalized to the typed exception, so every caller has one
            // failure mode to catch and the tick can never die on a
            // corrupt row.
            throw new ScheduleFormatException($normalized, $e->getMessage());
        }

        return \DateTimeImmutable::createFromInterface($next);
    }

    /**
     * The deployment timezone, or a diagnosis naming the variable. Called
     * wherever a schedule is interpreted, so a bad value fails with the
     * variable's name rather than PHP's bare "Unknown or bad timezone".
     *
     * @throws ScheduleFormatException when the identifier is not a timezone
     */
    public static function resolveTimezone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new ScheduleFormatException($timezone, 'TASKLOOM_TIMEZONE is not a valid timezone identifier. Use an IANA name such as America/Chicago or UTC.');
        }
    }

    /**
     * Null and blank normalize to null (no schedule); anything else is
     * trimmed. Stored schedules are the trimmed form, so '' can never
     * persist as a schedule.
     */
    public static function normalize(?string $schedule): ?string
    {
        if (null === $schedule) {
            return null;
        }

        $trimmed = trim($schedule);

        return '' === $trimmed ? null : $trimmed;
    }
}
