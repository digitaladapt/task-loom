<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\ScheduleNarrator;
use PHPUnit\Framework\TestCase;

/**
 * The schedule in plain English (SPEC §14, admin UI §8): "runs once per day at
 * 7:00am (America/Chicago)" plus the next few real occurrences.
 *
 * The point of these assertions is that a human reading the editor learns two
 * things without parsing cron: *when* it runs, and *whether that is what they
 * meant* — including the timezone, because 07:00 in the wrong zone is the
 * quiet wrongness SPEC §14.4 exists to prevent.
 */
final class ScheduleNarratorTest extends TestCase
{
    private function narrator(string $timezone = 'America/Chicago'): ScheduleNarrator
    {
        return new ScheduleNarrator($timezone);
    }

    public function testDailyScheduleIsDescribedInTheDeploymentTimezone(): void
    {
        self::assertSame(
            'runs once per day at 7:00am (America/Chicago)',
            $this->narrator()->describe('0 7 * * *'),
        );
    }

    public function testWeekdayScheduleNamesTheDays(): void
    {
        self::assertSame(
            'runs every weekday (Mon–Fri) at 6:30am (America/Chicago)',
            $this->narrator()->describe('30 6 * * 1-5'),
        );
    }

    public function testIntervalSchedulesAreDescribedWithoutATime(): void
    {
        self::assertSame('runs every 15 minutes (America/Chicago)', $this->narrator()->describe('*/15 * * * *'));
        self::assertSame('runs once per hour, on the hour (America/Chicago)', $this->narrator()->describe('0 * * * *'));
    }

    public function testWeeklyScheduleNamesTheDay(): void
    {
        self::assertSame(
            'runs once per week on Fridays at 6:45pm (America/Chicago)',
            $this->narrator()->describe('45 18 * * 5'),
        );
    }

    public function testMonthlyScheduleNamesTheDayOfMonth(): void
    {
        self::assertSame(
            'runs once per month on day 15 at 9:00am (America/Chicago)',
            $this->narrator()->describe('0 9 15 * *'),
        );
    }

    public function testNoScheduleDescribesAsNull(): void
    {
        self::assertNull($this->narrator()->describe(null));
        self::assertNull($this->narrator()->describe(''));
        self::assertNull($this->narrator()->describe('   '));
    }

    /**
     * A schedule that is not a preset shape still gets an honest sentence
     * rather than an invented one — and an unparseable one says so.
     */
    public function testNonPresetExpressionsAreDescribedHonestly(): void
    {
        // A single weekday *is* the weekly preset shape, so it is described
        // as the preset — the point is that the sentence is true, not that it
        // is short.
        self::assertSame(
            'runs once per week on Tuesdays at 8:00am (America/Chicago)',
            $this->narrator()->describe('0 8 * * 2'),
        );

        // Day-of-month 3 is not one of the recognised monthly shapes (the
        // preset writes the day the operator picked, and 3 is not what any
        // current task holds), so the fallback states the reading instead.
        self::assertSame(
            'runs once per month on day 3 at 3:00am (America/Chicago)',
            $this->narrator()->describe('0 3 3 * *'),
        );

        // A shape the narrator cannot state safely: the raw expression is
        // quoted rather than guessed at.
        self::assertSame(
            'runs on the schedule "0 8,12 * * *" (America/Chicago)',
            $this->narrator()->describe('0 8,12 * * *'),
        );
    }

    public function testTheTimezoneIsAlwaysNamed(): void
    {
        foreach (['0 7 * * *', '*/15 * * * *', '0 8,12 * * *'] as $expression) {
            self::assertStringContainsString(
                '(UTC)',
                (string) $this->narrator('UTC')->describe($expression),
                \sprintf('"%s" must name the timezone it fires in', $expression),
            );
        }
    }

    /**
     * Occurrences are computed in the deployment timezone — that is the whole
     * point of `TASKLOOM_TIMEZONE`. Same schedule, two zones, two answers.
     */
    public function testUpcomingOccurrencesAreInTheDeploymentTimezone(): void
    {
        $after = new \DateTimeImmutable('2026-06-15 00:00:00', new \DateTimeZone('UTC'));

        $chicago = $this->narrator('America/Chicago')->upcoming('0 7 * * *', $after, 2);
        $utc = $this->narrator('UTC')->upcoming('0 7 * * *', $after, 2);

        self::assertCount(2, $chicago);
        self::assertCount(2, $utc);
        self::assertSame('America/Chicago', $chicago[0]->getTimezone()->getName());
        self::assertSame('UTC', $utc[0]->getTimezone()->getName());

        // 07:00 Chicago is 12:00 UTC (CDT, -5) — different instants.
        self::assertSame('07:00', $chicago[0]->format('H:i'));
        self::assertSame('07:00', $utc[0]->format('H:i'));
        self::assertNotSame($chicago[0]->getTimestamp(), $utc[0]->getTimestamp());
    }

    public function testUpcomingReturnsTheRequestedNumberOfOccurrences(): void
    {
        $after = new \DateTimeImmutable('2026-06-15 00:00:00', new \DateTimeZone('UTC'));

        self::assertCount(1, $this->narrator()->upcoming('0 7 * * *', $after, 1));
        self::assertCount(3, $this->narrator()->upcoming('0 7 * * *', $after, 3));
        self::assertCount(5, $this->narrator()->upcoming('0 7 * * *', $after, 5));
    }

    public function testUpcomingIsEmptyWithoutASchedule(): void
    {
        $after = new \DateTimeImmutable();

        self::assertSame([], $this->narrator()->upcoming(null, $after));
        self::assertSame([], $this->narrator()->upcoming('', $after));
    }

    /**
     * A display surface must never throw at a human: an invalid expression
     * yields no occurrences instead. The enable gate is where an invalid
     * schedule is refused (SPEC §14.5).
     */
    public function testUpcomingIsEmptyForAnInvalidExpression(): void
    {
        self::assertSame([], $this->narrator()->upcoming('not a cron', new \DateTimeImmutable()));
    }

    public function testANarratorWithAnInvalidTimezoneFailsLoudly(): void
    {
        $narrator = $this->narrator('Not/AZone');

        // Describing still names the configured value — that is the diagnosis
        // a human needs (the timezone is wrong, and here is the wrong value).
        self::assertSame(
            'runs once per day at 7:00am (Not/AZone)',
            $narrator->describe('0 7 * * *'),
        );

        // Computing occurrences cannot proceed, and names the variable rather
        // than surfacing PHP's bare "Unknown or bad timezone".
        try {
            $narrator->upcoming('0 7 * * *', new \DateTimeImmutable());
            self::fail('an invalid timezone must not silently yield occurrences');
        } catch (\App\Scheduler\ScheduleFormatException $e) {
            self::assertStringContainsString('TASKLOOM_TIMEZONE', $e->getMessage());
        }
    }
}
