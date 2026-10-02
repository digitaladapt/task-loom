<?php

declare(strict_types=1);

namespace App\Tests\Unit\RunEngine;

use App\RunEngine\FleetOwnership;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The boot-sweep gate (SPEC §6.2).
 *
 * This is a security-shaped parse, so the cases that matter are the ones that
 * must NOT grant authority. An unearned reap can clear a live claim and
 * re-dispatch work a running worker is holding; a missed reap costs one hour
 * of waiting for the staleness window. The asymmetry is why everything
 * unrecognized fails closed — and why the unrecognized case is a distinct
 * value rather than quietly "false": a deployment must not be able to believe
 * a sweep is armed when a typo disarmed it.
 */
final class FleetOwnershipTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, FleetOwnership}>
     */
    public static function values(): iterable
    {
        yield 'affirmative: 1' => ['1', FleetOwnership::Owner];
        yield 'affirmative: true' => ['true', FleetOwnership::Owner];
        yield 'affirmative: on' => ['on', FleetOwnership::Owner];
        yield 'affirmative is case-insensitive' => ['TRUE', FleetOwnership::Owner];
        yield 'affirmative tolerates surrounding whitespace' => [' 1 ', FleetOwnership::Owner];

        yield 'unset is a plain non-owner, not a mistake' => [null, FleetOwnership::NotOwner];
        yield 'empty is a plain non-owner' => ['', FleetOwnership::NotOwner];
        yield 'whitespace-only is a plain non-owner' => ['   ', FleetOwnership::NotOwner];
        yield '0 is off' => ['0', FleetOwnership::NotOwner];
        yield 'false is off' => ['false', FleetOwnership::NotOwner];
        yield 'off is off' => ['off', FleetOwnership::NotOwner];

        // Anything the operator might plausibly type and get wrong. These must
        // not silently mean "no": they are reported.
        yield 'yes is not recognized' => ['yes', FleetOwnership::Unrecognized];
        yield 'y is not recognized' => ['y', FleetOwnership::Unrecognized];
        yield '2 is not recognized' => ['2', FleetOwnership::Unrecognized];
        yield 'a path is not recognized' => ['/run/taskloom', FleetOwnership::Unrecognized];
    }

    #[DataProvider('values')]
    public function testParse(?string $value, FleetOwnership $expected): void
    {
        self::assertSame($expected, FleetOwnership::parse($value));
    }

    public function testOnlyTheOwnerCaseGrantsAuthority(): void
    {
        self::assertTrue(FleetOwnership::Owner->ownsFleet());

        foreach ([FleetOwnership::NotOwner, FleetOwnership::Unrecognized] as $case) {
            self::assertFalse($case->ownsFleet(), $case->name.' must not grant fleet ownership');
        }
    }

    public function testUnrecognizedIsDistinguishableFromPlainNonOwner(): void
    {
        // The whole reason the enum has three cases: "you are not the owner"
        // and "you said something we do not understand" deserve different
        // treatment (silence vs. a warning), and only one of them is routine.
        self::assertNotSame(FleetOwnership::NotOwner, FleetOwnership::Unrecognized);
    }

    public function testAcceptedValuesAreTheOnesParseHonours(): void
    {
        // A guard against the two drifting apart: every advertised spelling
        // must actually work, and the list must not be empty (an empty list
        // would make the warning message useless).
        self::assertNotEmpty(FleetOwnership::acceptedValues());

        foreach (FleetOwnership::acceptedValues() as $accepted) {
            self::assertSame(
                FleetOwnership::Owner,
                FleetOwnership::parse($accepted),
                \sprintf('"%s" is advertised as accepted but does not parse as one', $accepted),
            );
        }
    }
}
