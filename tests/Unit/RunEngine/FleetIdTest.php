<?php

declare(strict_types=1);

namespace App\Tests\Unit\RunEngine;

use App\RunEngine\FleetId;
use PHPUnit\Framework\TestCase;

/**
 * The fleet label (SPEC §6.2).
 *
 * This class is deliberately small, because after one false start it no longer
 * holds any decision. A first version exposed `mayClearClaimFrom()`, which read
 * like a permission check and was used as one — and that was the bug: on a
 * restart, *every* leftover claim carries the previous start's id, so "not
 * mine" is true of all of them and the sweep declined everything it was
 * supposed to fix. The decision moved to ClaimReaper (which asks what could
 * still be in flight, a question with a real answer); what remains here is a
 * label and a reader for it.
 *
 * The tests below are therefore about *reading an environment variable
 * honestly*: unset is normal, empty is not an identity, and nothing here
 * authorizes anything. The regression test that matters is not here — it is
 * `testASecondsOldClaimFromThisFleetIsStillReaped` in RunStartupRequeueTest,
 * where the two sides use *different* ids, as production does.
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
        // The ordinary case: most processes that run a turn are not a fleet.
        self::assertNull(FleetId::current(), 'a process that is not a fleet has no label');
    }

    public function testCurrentIsNullWhenEmptyOrWhitespace(): void
    {
        // A template that failed to interpolate must not produce an identity:
        // treating "" as one would make every such process "the same fleet" as
        // every other.
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

    public function testIsOursOnlyMatchesAPositiveIdentity(): void
    {
        putenv(FleetId::ENV.'=start-2');

        self::assertTrue(FleetId::isOurs('start-2'));
        self::assertFalse(FleetId::isOurs('start-1'), 'the previous start is a different fleet');
        self::assertFalse(FleetId::isOurs(null), 'an unlabelled claim is nobody\'s');

        putenv(FleetId::ENV);
        self::assertFalse(FleetId::isOurs('start-2'), 'a process with no label owns nothing');
    }

    public function testTwoStartsOfOneDeploymentAreDifferentFleets(): void
    {
        // The property that makes the label useful for *explaining* a leftover
        // claim ("the previous run of this container held it") — and, read the
        // other way round, the property that made it useless for deciding:
        // start-2 cannot recognise start-1's claims as its own, which is
        // exactly the situation a restart creates.
        putenv(FleetId::ENV.'=start-1');
        $first = FleetId::current();

        putenv(FleetId::ENV.'=start-2');
        $second = FleetId::current();

        self::assertNotNull($first);
        self::assertNotSame($first, $second, 'a restart is a new fleet; if these were equal, "my predecessor" would be unknowable');
        self::assertFalse(FleetId::isOurs($first), 'and so the successor cannot claim the predecessor\'s label as its own');
    }
}
