<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\ScheduleExpression;
use App\Scheduler\ScheduleFormatException;
use App\Scheduler\SchedulePreset;
use App\Scheduler\SchedulePresetMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The admin editor's preset vocabulary (SPEC §14): compose() turns the
 * picker's fields into cron, fromExpression() recognises what it produced.
 *
 * The round trip is the property that matters. If compose() and
 * fromExpression() disagree, an operator opens a saved task and sees "custom
 * cron" for a schedule the UI itself wrote — or worse, a preset that rewrites
 * it on save. Both directions are asserted here, and the recognition is
 * asserted to be *narrow*: a schedule the presets did not compose must not be
 * claimed by one.
 */
final class SchedulePresetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function compositions(): iterable
    {
        yield 'every 5 minutes' => [SchedulePreset::EveryFiveMinutes->value, '', '', '', '*/5 * * * *'];
        yield 'every 15 minutes' => [SchedulePreset::EveryFifteenMinutes->value, '', '', '', '*/15 * * * *'];
        yield 'every 30 minutes' => [SchedulePreset::EveryThirtyMinutes->value, '', '', '', '*/30 * * * *'];
        yield 'hourly' => [SchedulePreset::Hourly->value, '', '', '', '0 * * * *'];
        yield 'daily' => [SchedulePreset::Daily->value, '07:00', '', '', '0 7 * * *'];
        yield 'daily, afternoon' => [SchedulePreset::Daily->value, '13:45', '', '', '45 13 * * *'];
        yield 'daily, midnight' => [SchedulePreset::Daily->value, '00:00', '', '', '0 0 * * *'];
        yield 'weekdays' => [SchedulePreset::Weekdays->value, '06:30', '', '', '30 6 * * 1-5'];
        yield 'weekly, Sunday' => [SchedulePreset::Weekly->value, '09:15', '0', '', '15 9 * * 0'];
        yield 'weekly, Friday' => [SchedulePreset::Weekly->value, '18:00', '5', '', '0 18 * * 5'];
        yield 'monthly' => [SchedulePreset::Monthly->value, '08:00', '', '15', '0 8 15 * *'];
        yield 'monthly, first' => [SchedulePreset::Monthly->value, '00:30', '', '1', '30 0 1 * *'];
    }

    #[DataProvider('compositions')]
    public function testComposeBuildsTheExpectedCron(string $preset, string $time, string $weekday, string $day, string $expected): void
    {
        $composed = SchedulePreset::from($preset)->compose($time, $weekday, $day);

        self::assertSame($expected, $composed);
        // Every composition must also be a schedule the rest of the app
        // accepts — the picker can never offer something the save would refuse.
        (new ScheduleExpression())->assertValid($composed);
    }

    /**
     * A composed expression must be recognised as the preset that produced
     * it, with the fields needed to reproduce it exactly.
     *
     * HH:MM is the crux: the picker reads hours-then-minutes, cron is
     * minute-then-hour. An early version had those swapped, so "06:30"
     * composed to `06 30 * * *` (i.e. 30:06). Asserting the composed
     * expression *and* the recovered time catches it from both sides.
     */
    public function testComposedExpressionsRoundTripThroughRecognition(): void
    {
        foreach ([
            [SchedulePreset::EveryFiveMinutes, '', ''],
            [SchedulePreset::EveryFifteenMinutes, '', ''],
            [SchedulePreset::EveryThirtyMinutes, '', ''],
            [SchedulePreset::Hourly, '', ''],
            [SchedulePreset::Daily, '07:00', ''],
            [SchedulePreset::Daily, '23:05', ''],
            [SchedulePreset::Weekdays, '06:30', ''],
            [SchedulePreset::Weekly, '18:45', '5'],
            [SchedulePreset::Monthly, '09:00', '15'],
        ] as [$preset, $time, $extra]) {
            $weekday = SchedulePreset::Weekly === $preset ? $extra : '';
            $dayOfMonth = SchedulePreset::Monthly === $preset ? $extra : '';

            $expression = $preset->compose($time, $weekday, $dayOfMonth);
            $match = SchedulePreset::fromExpression($expression);

            self::assertInstanceOf(SchedulePresetMatch::class, $match, \sprintf('"%s" was not recognised', $expression));
            self::assertSame($preset, $match->preset, \sprintf('"%s" round-tripped to the wrong preset', $expression));

            if ('' !== $time) {
                self::assertSame($time, $match->time, \sprintf('"%s" round-tripped to the wrong time', $expression));
            }
        }
    }

    public function testRecognitionRecoversWeekdayAndDayOfMonth(): void
    {
        $weekly = SchedulePreset::fromExpression('45 18 * * 3');
        self::assertNotNull($weekly);
        self::assertSame(SchedulePreset::Weekly, $weekly->preset);
        self::assertSame(3, $weekly->weekday);
        self::assertSame('18:45', $weekly->time);

        $monthly = SchedulePreset::fromExpression('0 9 15 * *');
        self::assertNotNull($monthly);
        self::assertSame(SchedulePreset::Monthly, $monthly->preset);
        self::assertSame(15, $monthly->dayOfMonth);
    }

    /**
     * The aliases an operator may already have in a task row are recognised
     * as the presets they are equivalent to.
     */
    public function testCommonAliasesAreRecognised(): void
    {
        $hourly = SchedulePreset::fromExpression('@hourly');
        self::assertNotNull($hourly);
        self::assertSame(SchedulePreset::Hourly, $hourly->preset);

        $daily = SchedulePreset::fromExpression('@daily');
        self::assertNotNull($daily);
        self::assertSame(SchedulePreset::Daily, $daily->preset);
        self::assertSame('00:00', $daily->time);
    }

    /**
     * Recognition must be narrow. Each of these is a legitimate cron schedule
     * that no preset composed — reopening such a task as a preset would
     * rewrite the operator's schedule on the next save.
     */
    public function testUnrecognisedExpressionsStayCustom(): void
    {
        foreach ([
            '0 8,12 * * *',       // two times a day
            '0 8-17 * * *',       // an hour range
            '*/7 * * * *',        // an interval not offered
            '0 0 1,15 * *',       // two days a month
            '0 0 * 6 *',          // monthly by month
            '15 3 * * 1,3,5',     // specific weekdays
            '0 0 29 2 *',         // February 29
            '0 7 * * 1-4',        // a weekday range, but not the one the preset writes
            '0 7 * * 0-4',        // Sunday-through-Thursday
        ] as $expression) {
            self::assertNull(
                SchedulePreset::fromExpression($expression),
                \sprintf('"%s" must not be claimed by a preset', $expression),
            );
        }
    }

    public function testInvalidTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('24:00');

        SchedulePreset::Daily->compose('24:00', '', '');
    }

    public function testMissingWeekdayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('day of the week');

        SchedulePreset::Weekly->compose('09:00', '', '');
    }

    public function testMonthlyDayOutOfRangeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('day of the month');

        SchedulePreset::Monthly->compose('09:00', '', '32');
    }

    /**
     * A time is only meaningful for the presets that carry one; asking an
     * interval preset for a time is a wiring bug (the picker hides the field),
     * and it must not silently use it.
     */
    public function testIntervalPresetsIgnoreTheTimeField(): void
    {
        self::assertSame('0 * * * *', SchedulePreset::Hourly->compose('13:45', '', ''));
        self::assertSame('*/15 * * * *', SchedulePreset::EveryFifteenMinutes->compose('13:45', '', ''));
    }

    public function testMatchRendersATwelveHourTimeLabel(): void
    {
        $match = SchedulePreset::fromExpression('30 6 * * *');
        self::assertNotNull($match);
        self::assertSame('6:30am', $match->timeLabel());

        $evening = SchedulePreset::fromExpression('45 18 * * *');
        self::assertNotNull($evening);
        self::assertSame('6:45pm', $evening->timeLabel());

        $midnight = SchedulePreset::fromExpression('0 0 * * *');
        self::assertNotNull($midnight);
        self::assertSame('12:00am', $midnight->timeLabel());

        $noon = SchedulePreset::fromExpression('0 12 * * *');
        self::assertNotNull($noon);
        self::assertSame('12:00pm', $noon->timeLabel());
    }

    public function testFromExpressionRejectsMalformedSchedules(): void
    {
        self::assertNull(SchedulePreset::fromExpression('not a cron'));
        self::assertNull(SchedulePreset::fromExpression('0 7 * *'));
        self::assertNull(SchedulePreset::fromExpression(''));
    }

    public function testPresetFieldRequirementsAreDeclared(): void
    {
        self::assertTrue(SchedulePreset::Daily->needsTime());
        self::assertTrue(SchedulePreset::Weekdays->needsTime());
        self::assertTrue(SchedulePreset::Weekly->needsWeekday());
        self::assertFalse(SchedulePreset::Weekly->needsDayOfMonth());
        self::assertTrue(SchedulePreset::Monthly->needsDayOfMonth());
        self::assertFalse(SchedulePreset::Hourly->needsTime());

        // Spot-check the weekday vocabulary the picker renders from.
        self::assertSame('Sunday', SchedulePreset::WEEKDAY_NAMES[0]);
        self::assertSame('Friday', SchedulePreset::WEEKDAY_NAMES[5]);
        self::assertCount(7, SchedulePreset::WEEKDAY_NAMES);
    }

    public function testFormatExceptionIsTyped(): void
    {
        $this->expectException(ScheduleFormatException::class);
        (new ScheduleExpression())->assertValid('definitely not cron');
    }
}
