<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * The schedules the admin UI offers without typing cron (SPEC §14).
 *
 * A preset is a two-way vocabulary, not just a shortcut:
 *
 *  - compose()  turns the editor's fields (time, weekday, day-of-month) into
 *               the stored cron expression — the composition happens here,
 *               server-side, so the picker and the persisted value can never
 *               disagree about what "every weekday at 8am" means.
 *  - fromExpression() recognises the expanded form a preset produces, so an
 *               existing task's schedule reopens on the preset that authored
 *               it (an editor that showed "custom cron" for every schedule it
 *               produced itself would make the friendly path a one-way trip).
 *
 * Recognition is deliberately narrow: only the shapes the presets themselves
 * compose are recognised, plus the common aliases. Anything else — hour
 * lists, ranges, second-level tricks — reopens as a custom expression, which
 * is honest: the UI shows the cron and the next occurrences, it never guesses
 * a preset that would silently rewrite the schedule on save.
 *
 * Schedules are wall-clock in the deployment timezone (SPEC §14.4); the
 * expressions composed here are plain five-field cron, interpreted by
 * ScheduleExpression in TASKLOOM_TIMEZONE.
 */
enum SchedulePreset: string
{
    case EveryFiveMinutes = 'every_5_minutes';
    case EveryFifteenMinutes = 'every_15_minutes';
    case EveryThirtyMinutes = 'every_30_minutes';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** Weekday names in cron day-of-week order (0 = Sunday). */
    public const array WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function label(): string
    {
        return match ($this) {
            self::EveryFiveMinutes => 'Every 5 minutes',
            self::EveryFifteenMinutes => 'Every 15 minutes',
            self::EveryThirtyMinutes => 'Every 30 minutes',
            self::Hourly => 'Every hour, on the hour',
            self::Daily => 'Every day',
            self::Weekdays => 'Every weekday (Mon–Fri)',
            self::Weekly => 'Every week on a chosen day',
            self::Monthly => 'Every month on a chosen day',
        };
    }

    public function needsTime(): bool
    {
        return match ($this) {
            self::Daily, self::Weekdays, self::Weekly, self::Monthly => true,
            default => false,
        };
    }

    public function needsWeekday(): bool
    {
        return self::Weekly === $this;
    }

    public function needsDayOfMonth(): bool
    {
        return self::Monthly === $this;
    }

    /**
     * Compose this preset's cron expression from the editor's fields.
     *
     * @param string $time       'HH:MM' — required by the time-bearing presets
     * @param string $weekday    '0'..'6' (0 = Sunday) — required by Weekly
     * @param string $dayOfMonth '1'..'31' — required by Monthly
     *
     * @throws \InvalidArgumentException when a required field is missing or out of range
     */
    public function compose(string $time, string $weekday, string $dayOfMonth): string
    {
        // Interval presets are fixed clockwork: they carry no time field, so
        // they cannot drift to "next hour" semantics under a value the
        // operator never chose.
        if (!$this->needsTime()) {
            return match ($this) {
                self::EveryFiveMinutes => '*/5 * * * *',
                self::EveryFifteenMinutes => '*/15 * * * *',
                self::EveryThirtyMinutes => '*/30 * * * *',
                self::Hourly => '0 * * * *',
                default => throw new \LogicException(\sprintf('Preset "%s" needs a time but declares none.', $this->value)),
            };
        }

        [$minute, $hour] = self::splitTime($time);

        return match ($this) {
            self::Daily => \sprintf('%d %d * * *', $minute, $hour),
            self::Weekdays => \sprintf('%d %d * * 1-5', $minute, $hour),
            self::Weekly => \sprintf('%d %d * * %d', $minute, $hour, self::weekdayValue($weekday)),
            self::Monthly => \sprintf('%d %d %d * *', $minute, $hour, self::dayOfMonthValue($dayOfMonth)),
            default => throw new \LogicException(\sprintf('Preset "%s" has no time but declares one.', $this->value)),
        };
    }

    /**
     * Recognise a stored expression as one of the preset shapes — its own
     * composition, or the equivalent alias. Null when the expression is not a
     * preset shape (the caller keeps it as a custom expression).
     */
    public static function fromExpression(string $expression): ?SchedulePresetMatch
    {
        $normalized = ScheduleExpression::normalize($expression);
        if (null === $normalized) {
            return null;
        }

        $parts = preg_split('/\s+/', self::expandAlias($normalized));
        if (!\is_array($parts) || 5 !== \count($parts)) {
            return null;
        }
        [$minute, $hour, $dayOfMonth, $month, $weekday] = $parts;

        if ('*' === $hour && '*' === $dayOfMonth && '*' === $month && '*' === $weekday) {
            $interval = match ($minute) {
                '*/5' => self::EveryFiveMinutes,
                '*/15' => self::EveryFifteenMinutes,
                '*/30' => self::EveryThirtyMinutes,
                '0' => self::Hourly,
                default => null,
            };

            return null === $interval ? null : new SchedulePresetMatch($interval, '', null, null);
        }

        if (!ctype_digit($minute) || !ctype_digit($hour)) {
            return null;
        }

        $time = \sprintf('%02d:%02d', (int) $hour, (int) $minute);

        if ('*' === $dayOfMonth && '*' === $month) {
            if ('*' === $weekday) {
                return new SchedulePresetMatch(self::Daily, $time, null, null);
            }
            if ('1-5' === $weekday) {
                return new SchedulePresetMatch(self::Weekdays, $time, null, null);
            }
            if (ctype_digit($weekday) && (int) $weekday <= 6) {
                return new SchedulePresetMatch(self::Weekly, $time, (int) $weekday, null);
            }

            return null;
        }

        if ('*' === $weekday && '*' === $month && ctype_digit($dayOfMonth) && (int) $dayOfMonth >= 1 && (int) $dayOfMonth <= 31) {
            return new SchedulePresetMatch(self::Monthly, $time, null, (int) $dayOfMonth);
        }

        return null;
    }

    /**
     * The cron aliases worth recognising as presets. Anything else passes
     * through untouched (and reopens as a custom expression).
     */
    private static function expandAlias(string $expression): string
    {
        return match (strtolower($expression)) {
            '@hourly' => '0 * * * *',
            '@daily', '@midnight', '@everyday' => '0 0 * * *',
            '@weekly' => '0 0 * * 0',
            '@monthly' => '0 0 1 * *',
            default => $expression,
        };
    }

    /**
     * Parse an HH:MM wall clock (the <input type="time"> value, and the way
     * times are displayed everywhere else in this UI) into the cron fields it
     * represents.
     *
     * The order is the whole point: HH:MM reads hours-then-minutes, while a
     * cron expression is minute-then-hour. Getting it backwards would make
     * "06:30" mean 06:30 cron = 30:06 wall clock — which is exactly the bug
     * this docblock exists to prevent recurring.
     *
     * @return array{0: int, 1: int} minute first (cron order), hour second
     */
    private static function splitTime(string $time): array
    {
        $parts = explode(':', trim($time));
        if (2 !== \count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            throw new \InvalidArgumentException(\sprintf('A time in HH:MM form is required (got "%s").', $time));
        }

        $hour = (int) $parts[0];
        $minute = (int) $parts[1];
        if ($hour > 23 || $minute > 59) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid time of day — use HH:MM between 00:00 and 23:59.', $time));
        }

        return [$minute, $hour];
    }

    private static function weekdayValue(string $weekday): int
    {
        if (!ctype_digit(trim($weekday)) || (int) $weekday > 6) {
            throw new \InvalidArgumentException('Pick a day of the week for a weekly schedule.');
        }

        return (int) $weekday;
    }

    private static function dayOfMonthValue(string $dayOfMonth): int
    {
        if (!ctype_digit(trim($dayOfMonth)) || (int) $dayOfMonth < 1 || (int) $dayOfMonth > 31) {
            throw new \InvalidArgumentException('Pick a day of the month (1–31) for a monthly schedule.');
        }

        return (int) $dayOfMonth;
    }
}
