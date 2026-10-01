<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\Grounding;
use App\Context\Units;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The grounding block (SPEC §4.2): the deployment's clock and units,
 * stated once at the bottom of every run's prompt head.
 *
 * The clock half is a regression guard. The block used to render
 * `new DateTimeImmutable('now')` in the *container's* default zone, so a
 * deployment whose TASKLOOM_TIMEZONE said America/Chicago still told every
 * run it was 09:15 (UTC). A model that reads "today" off the block then
 * stamps deliverables with a date the operator is not on — the exact
 * quiet-wrongness the scheduler's own timezone rule exists to prevent,
 * leaking back in through the prompt.
 */
final class GroundingTest extends TestCase
{
    /**
     * One instant, three deployments: the block speaks each deployment's
     * wall clock and names its zone, rather than reporting UTC everywhere.
     */
    #[DataProvider('timezones')]
    public function testTheBlockSpeaksTheDeploymentsWallClock(string $timezone, string $expectedDate, string $expectedTime): void
    {
        // 2026-07-05 03:30 UTC — a Saturday evening in Chicago, a Sunday
        // morning in Tokyo, and a date boundary between them: the strongest
        // case that the conversion really happens.
        $instant = new \DateTimeImmutable('2026-07-05 03:30:00', new \DateTimeZone('UTC'));

        $rendered = (new Grounding(timezone: $timezone, now: $instant))->render();

        self::assertStringContainsString('Current date: '.$expectedDate, $rendered);
        self::assertStringContainsString('Current time: '.$expectedTime.' ('.$timezone.')', $rendered);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function timezones(): iterable
    {
        yield 'UTC' => ['UTC', 'Sunday, July 5, 2026', '03:30'];
        yield 'America/Chicago (the date differs)' => ['America/Chicago', 'Saturday, July 4, 2026', '22:30'];
        yield 'Asia/Tokyo' => ['Asia/Tokyo', 'Sunday, July 5, 2026', '12:30'];
    }

    /**
     * A clock born in another zone is normalized to the deployment's: the
     * injected test clock must not be able to disagree with the zone the
     * block names, or the block could say "(America/Chicago)" beside a UTC
     * time.
     */
    public function testAClockFromAnotherZoneIsNormalizedToTheDeploymentZone(): void
    {
        $utcFedClock = new \DateTimeImmutable('2026-07-05 03:30:00', new \DateTimeZone('UTC'));

        $rendered = (new Grounding(timezone: 'America/Chicago', now: $utcFedClock))->render();

        self::assertStringContainsString('22:30 (America/Chicago)', $rendered);
        self::assertStringNotContainsString('03:30', $rendered);
    }

    #[DataProvider('units')]
    public function testUnitsAreStatedAsConfigured(?string $configured, string $expected): void
    {
        $rendered = (new Grounding(timezone: 'UTC', units: $configured))->render();

        self::assertStringContainsString('Units: '.$expected, $rendered);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function units(): iterable
    {
        yield 'explicit metric' => ['metric', 'metric'];
        yield 'explicit imperial' => ['imperial', 'imperial'];
        yield 'unset falls back to metric (the historic behavior)' => [null, 'metric'];
        yield 'empty string is unset' => ['', 'metric'];
        yield 'surrounding whitespace is tolerated' => ['  imperial  ', 'imperial'];
    }

    /**
     * A misspelled unit fails loudly and names its variable — it must never
     * quietly render the default, which would tell every run to report in
     * units the operator did not choose.
     */
    public function testAnUnknownUnitIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TASKLOOM_UNITS.*furlongs/s');

        new Grounding(timezone: 'UTC', units: 'furlongs');
    }

    /**
     * Same refusal for the clock: an invalid zone is a boot-time error
     * naming TASKLOOM_TIMEZONE, not a silent fallback to UTC.
     */
    public function testAnInvalidTimezoneIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TASKLOOM_TIMEZONE.*Mars\/Olympus_Mons/s');

        new Grounding(timezone: 'Mars/Olympus_Mons');
    }

    /**
     * The block is harness-owned, fixed shape: the three core lines always
     * appear, in order, and the location line only when one is configured.
     */
    public function testTheBlockKeepsItsShape(): void
    {
        $noLocation = (new Grounding(timezone: 'UTC'))->render();
        $lines = explode("\n", $noLocation);

        self::assertCount(3, $lines, 'date, time, units — and no location line when none is set');
        self::assertStringStartsWith('Current date: ', $lines[0]);
        self::assertStringStartsWith('Current time: ', $lines[1]);
        self::assertStringStartsWith('Units: ', $lines[2]);

        $withLocation = (new Grounding(timezone: 'UTC', location: 'Reykjavik'))->render();
        self::assertStringEndsWith('Location: Reykjavik', $withLocation);
    }

    public function testUnitsEnumParsesItsOwnValues(): void
    {
        self::assertSame(Units::Metric, Units::fromConfig('metric'));
        self::assertSame(Units::Imperial, Units::fromConfig('imperial'));
        self::assertSame(Units::Metric, Units::fromConfig(null));
    }

    /**
     * The location line, when configured.
     *
     * Free text, so this is about what reaches the model: the place it is
     * told to treat as "here", in the same once-per-run block as the clock
     * and the units.
     */
    #[DataProvider('locations')]
    public function testLocationIsRenderedWhenConfigured(?string $configured, ?string $expected): void
    {
        $rendered = (new Grounding(timezone: 'UTC', location: $configured))->render();

        if (null === $expected) {
            self::assertStringNotContainsString('Location:', $rendered, 'an unset or blank location must omit the line, not render it empty');

            return;
        }

        self::assertStringContainsString('Location: '.$expected, $rendered);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function locations(): iterable
    {
        yield 'a city' => ['Chicago', 'Chicago'];
        yield 'city and country' => ['Reykjavik, Iceland', 'Reykjavik, Iceland'];
        yield 'unset' => [null, null];
        yield 'empty string is unset' => ['', null];
        yield 'whitespace-only is unset' => ['   ', null];
        yield 'surrounding whitespace is trimmed' => ['  Chicago  ', 'Chicago'];
        // A file or shell export usually carries a trailing newline. Trimming
        // first means it never counts as a second line — refusing it would
        // fail the most ordinary way of setting the variable.
        yield 'trailing newline is trimmed, not treated as a second line' => ["Chicago\n", 'Chicago'];
    }

    /**
     * Blank means unset: no "Location:" line at all.
     *
     * A container deployment forwards TASKLOOM_LOCATION as an empty string
     * when the operator has not set it (`${TASKLOOM_LOCATION:-}`), so the
     * empty case is the *normal* one, not an edge — an empty line would put
     * a lie-shaped blank into every run's prompt on those deployments.
     */
    public function testBlankLocationIsIndistinguishableFromUnset(): void
    {
        $unset = (new Grounding(timezone: 'UTC'))->render();
        $blank = (new Grounding(timezone: 'UTC', location: '   '))->render();

        self::assertSame($unset, $blank);
    }

    /**
     * The block is one fact per line, so a location that spans lines is
     * refused.
     *
     * Without this, a single knob could forge what read like additional
     * harness-authored lines — "Location: Nowhere\nUnits: imperial" would
     * render as two lines that both look like the harness said them. It is
     * operator config rather than untrusted input, so this is about keeping
     * the shape guarantee honest, not about an attack.
     */
    #[DataProvider('multiLineLocations')]
    public function testAMultiLineLocationIsRefused(string $configured): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TASKLOOM_LOCATION.*single line/s');

        new Grounding(timezone: 'UTC', location: $configured);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiLineLocations(): iterable
    {
        yield 'newline' => ["Nowhere\nUnits: imperial"];
        yield 'carriage return' => ["Nowhere\rLocation: Somewhere"];
        yield 'CRLF' => ["Nowhere\r\nLocation: Somewhere"];
    }
}
