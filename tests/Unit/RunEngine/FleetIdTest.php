<?php

declare(strict_types=1);

namespace App\Tests\Unit\RunEngine;

use App\RunEngine\FleetId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fleet identity (SPEC §6.2) — and, more importantly, the cases where it
 * refuses to answer.
 *
 * mayClearClaimFrom() is the "am I allowed to clear this claim?" decision
 * itself, and it is deliberately not symmetric: exactly one input combination
 * authorizes, and every other combination declines. The asymmetry is the
 * design, not an oversight. Clearing a claim that a live fleet is holding
 * would hand the same run to two workers; declining a claim that was actually
 * dead merely costs time, because the engine's lease picks it up later. When
 * the cost of being wrong is that lopsided, refuse.
 *
 * This is also a direct regression guard on the bug that shipped in the first
 * cut of boot recovery: "10 seconds old" and "dead" were conflated because
 * nothing recorded *who* held the claim. The tests below are about identity,
 * not the clock — no assertion here cares how old a claim is.
 */
final class FleetIdTest extends TestCase
{
    private string|false $restore = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->restore = getenv(FleetId::ENV);
        putenv(FleetId::ENV);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (false === $this->restore) {
            putenv(FleetId::ENV);
        } else {
            putenv(FleetId::ENV.'='.$this->restore);
        }

        parent::tearDown();
    }

    public function testCurrentIsNullWhenUnset(): void
    {
        self::assertNull(FleetId::current(), 'a process that is not a fleet has no identity');
    }

    public function testCurrentIsNullWhenEmptyOrWhitespace(): void
    {
        // The entrypoint could plausibly hand over an empty variable (a
        // template that failed to interpolate); an empty identity is not an
        // identity, and treating "" as one would make every such process
        // "the same fleet" as every other.
        putenv(FleetId::ENV.'=');
        self::assertNull(FleetId::current());

        putenv(FleetId::ENV.'=   ');
        self::assertNull(FleetId::current());
    }

    public function testCurrentReturnsTheSetValue(): void
    {
        putenv(FleetId::ENV.'=2026-10-02-42-1234');
        self::assertSame('2026-10-02-42-1234', FleetId::current());
    }

    /**
     * The one authorization, and everything that must not be one.
     *
     * @return iterable<string, array{?string, ?string, bool}>
     */
    public static function clearability(): iterable
    {
        yield 'my own fleet: a dead predecessor, whatever the age' => ['fleet-a', 'fleet-a', true];

        yield 'another fleet: not abandoned, however old' => ['fleet-a', 'fleet-b', false];
        yield 'no identity: cannot attribute, so declines' => [null, 'fleet-a', false];
        yield 'claim with no owner: nothing proven, so declines' => ['fleet-a', null, false];
        yield 'neither side has an identity' => [null, null, false];

        // An empty string is "no identity" on either side, never a match:
        // otherwise two unrelated processes with unset-and-templated-to-empty
        // ids would consider each other the same fleet.
        yield 'empty identity is not an identity' => ['', 'fleet-a', false];
        yield 'an empty-stamped claim is not mine' => ['fleet-a', '', false];
    }

    #[DataProvider('clearability')]
    public function testMayClearClaimFrom(?string $mine, ?string $owner, bool $expected): void
    {
        if (null === $mine) {
            putenv(FleetId::ENV);
        } else {
            putenv(FleetId::ENV.'='.$mine);
        }

        self::assertSame($expected, FleetId::mayClearClaimFrom($owner));
    }

    public function testIdentityDistinguishesTwoStartsOfTheSameDeployment(): void
    {
        // The property the whole mechanism rests on: two consecutive starts of
        // one deployment are different fleets, so the second may clear what the
        // first left behind. If the id were stable across restarts (as a
        // compose-supplied value would be), boot recovery could never tell a
        // dead predecessor from a live peer, and this design would collapse
        // back into the recency heuristic it replaced.
        putenv(FleetId::ENV.'=start-1');
        self::assertTrue(FleetId::mayClearClaimFrom('start-1'));
        self::assertFalse(FleetId::mayClearClaimFrom('start-2'), 'a later start is a different fleet and must not adopt an earlier one\'s claim');

        putenv(FleetId::ENV.'=start-2');
        self::assertTrue(FleetId::mayClearClaimFrom('start-2'), 'the next start may clear the previous one');
        self::assertFalse(FleetId::mayClearClaimFrom('start-1'));
    }
}
