<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * A stored schedule recognised as one of the preset shapes: which preset,
 * and the field values that reproduce it exactly (SPEC §14). Returned by
 * SchedulePreset::fromExpression(); consumed by the editor (to reopen the
 * picker) and the narrator (to describe the schedule in English).
 */
final readonly class SchedulePresetMatch
{
    /**
     * @param string   $time       'HH:MM'; '' for presets that carry no time
     * @param int|null $weekday    0 = Sunday .. 6 = Saturday, for Weekly
     * @param int|null $dayOfMonth 1..31, for Monthly
     */
    public function __construct(
        public SchedulePreset $preset,
        public string $time,
        public ?int $weekday = null,
        public ?int $dayOfMonth = null,
    ) {
    }

    /** e.g. "7:00am" — 12-hour wall clock, the way schedules are spoken. */
    public function timeLabel(): string
    {
        if ('' === $this->time) {
            return '';
        }

        [$hours, $minutes] = array_map(intval(...), explode(':', $this->time));
        $suffix = $hours < 12 ? 'am' : 'pm';
        $display = $hours % 12;
        if (0 === $display) {
            $display = 12;
        }

        return \sprintf('%d:%02d%s', $display, $minutes, $suffix);
    }

    public function weekdayLabel(): string
    {
        return null === $this->weekday ? '' : SchedulePreset::WEEKDAY_NAMES[$this->weekday];
    }
}
