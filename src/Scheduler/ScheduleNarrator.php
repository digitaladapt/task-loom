<?php

declare(strict_types=1);

namespace App\Scheduler;

use Cron\CronExpression;

/**
 * A schedule in plain English, plus the times it will actually run
 * (SPEC §14, admin UI §8).
 *
 * The operator should never have to read cron to know what a task will do.
 * "Runs once per day at 7:00am (America/Chicago)" plus the next three
 * occurrences answers the only two questions that matter before enabling a
 * scheduled task: *when* does it run, and *is that what I meant*.
 *
 * Deliberately display-only. The narrator never rewrites the schedule and
 * never feeds the tick: the stored expression stays the record of truth
 * (SPEC §14.1), and the tick keeps interpreting it through
 * ScheduleExpression. If narration cannot make sense of an expression
 * (hand-edited data, an exotic shape), it says so instead of inventing a
 * description — and the occurrences, computed by the cron library itself,
 * remain correct regardless.
 *
 * The text names the deployment timezone because that is where the schedule
 * actually fires (SPEC §14.4) and where the occurrence list is rendered.
 */
final readonly class ScheduleNarrator
{
    public function __construct(
        private string $timezone,
    ) {
    }

    /**
     * One sentence, lower-case after the first word, e.g.
     * "runs once per day at 7:00am" or "runs every 15 minutes".
     */
    public function describe(?string $schedule): ?string
    {
        $normalized = ScheduleExpression::normalize($schedule);
        if (null === $normalized) {
            return null;
        }

        $match = SchedulePreset::fromExpression($normalized);
        if (null !== $match) {
            return $this->describePreset($match);
        }

        return $this->describeCustom($normalized);
    }

    /**
     * The next $count occurrences strictly after $after, in the deployment
     * timezone — what the operator is really asking when they look at a
     * schedule. Empty when the expression is invalid or has no future
     * occurrence.
     *
     * @return list<\DateTimeImmutable>
     */
    public function upcoming(?string $schedule, \DateTimeImmutable $after, int $count = 3): array
    {
        $normalized = ScheduleExpression::normalize($schedule);
        if (null === $normalized) {
            return [];
        }

        $timezone = ScheduleExpression::resolveTimezone($this->timezone);

        try {
            $cron = new CronExpression($normalized);
            $dates = $cron->getMultipleRunDates($count, $after->setTimezone($timezone), false, false, $this->timezone);
        } catch (\Throwable) {
            // Invalid or pathological expression: no occurrences to show.
            // The enable gate is where an invalid schedule is refused (§14.5);
            // a display surface must not throw at a human.
            return [];
        }

        return array_map(
            static fn (\DateTimeInterface $date): \DateTimeImmutable => \DateTimeImmutable::createFromInterface($date),
            $dates,
        );
    }

    private function describePreset(SchedulePresetMatch $match): string
    {
        $time = $match->timeLabel();
        $zone = $this->timezone;

        return match ($match->preset) {
            SchedulePreset::EveryFiveMinutes => \sprintf('runs every 5 minutes (%s)', $zone),
            SchedulePreset::EveryFifteenMinutes => \sprintf('runs every 15 minutes (%s)', $zone),
            SchedulePreset::EveryThirtyMinutes => \sprintf('runs every 30 minutes (%s)', $zone),
            SchedulePreset::Hourly => \sprintf('runs once per hour, on the hour (%s)', $zone),
            SchedulePreset::Daily => \sprintf('runs once per day at %s (%s)', $time, $zone),
            SchedulePreset::Weekdays => \sprintf('runs every weekday (Mon–Fri) at %s (%s)', $time, $zone),
            SchedulePreset::Weekly => \sprintf('runs once per week on %ss at %s (%s)', $match->weekdayLabel(), $time, $zone),
            SchedulePreset::Monthly => \sprintf(
                'runs once per month on day %d at %s (%s)',
                (int) $match->dayOfMonth,
                $time,
                $zone,
            ),
        };
    }

    /**
     * A non-preset expression still gets an honest sentence: the field-level
     * shape where it can be stated safely, the raw expression otherwise.
     */
    private function describeCustom(string $expression): string
    {
        $parts = preg_split('/\s+/', $expression);
        if (\is_array($parts) && 5 === \count($parts)) {
            [$minute, $hour, $dayOfMonth, $month, $weekday] = $parts;

            if (ctype_digit($minute) && ctype_digit($hour)) {
                $time = \sprintf('%02d:%02d', (int) $hour, (int) $minute);
                $label = (new SchedulePresetMatch(SchedulePreset::Daily, $time))->timeLabel();

                if ('*' === $dayOfMonth && '*' === $month && ctype_digit($weekday)) {
                    $name = SchedulePreset::WEEKDAY_NAMES[(int) $weekday % 7] ?? $weekday;

                    return \sprintf('runs every %s at %s (%s)', $name, $label, $this->timezone);
                }

                if ('*' === $weekday && '*' === $month && ctype_digit($dayOfMonth)) {
                    return \sprintf('runs every month on day %s at %s (%s)', $dayOfMonth, $label, $this->timezone);
                }
            }
        }

        return \sprintf('runs on the schedule "%s" (%s)', $expression, $this->timezone);
    }
}
