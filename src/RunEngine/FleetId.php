<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * This process group's identity as a worker fleet (SPEC §6.2).
 *
 * Boot recovery's hard question is not "is this claim old?" but "is the
 * process that holds it still alive?" — and the only honest way to answer a
 * question about identity is to have an identity. A recency heuristic cannot:
 * a container that is `down`'d and `up`'d inside a minute leaves claims
 * seconds old, indistinguishable by timestamp from one a live worker is
 * holding mid-turn. That is exactly the bug the first cut of this feature
 * shipped.
 *
 * So the fleet stamps itself onto every claim it takes, and boot recovery
 * compares a stale claim's owner against its own identity. A claim held by
 * *my* fleet is one I am entitled to clear, because that fleet's processes are
 * gone — and that reasoning holds whether it died ten seconds or ten hours ago,
 * and whether the container is being restarted on the same host or a different
 * one.
 *
 * ## Where the identity comes from
 *
 * The entrypoint generates it, because the entrypoint is what knows a fleet is
 * being started: `TASKLOOM_FLEET_ID` is set to a fresh value per container
 * start. It is deliberately **not** part of the compose env contract — compose
 * services share an environment anchor, so a fixed value there would be one
 * identity for every service and every restart, which is precisely the thing
 * that must differ from one start to the next. Same reasoning as
 * FleetOwnership's flag, and the same test guards it.
 *
 * This is the *same* rule as FleetOwnership, not a second one: that class
 * decides whether a process may sweep at all, this one decides what it may
 * sweep.
 *
 * ## The unanswerable cases deliberately do nothing
 *
 * Unset (a console command, a cron job, a process that never claimed a fleet
 * identity) and legacy claims written before the column existed both yield no
 * identity. Neither may be distilled into "not mine, therefore dead": a fresh
 * `app:run:now` in another terminal has no fleet, but it is very much alive.
 * Those runs fall back to the engine's lease, which is always safe and merely
 * slow. When in doubt, this class refuses to answer.
 */
final readonly class FleetId
{
    /** The env var the entrypoint sets to a fresh value on each container start. */
    public const string ENV = 'TASKLOOM_FLEET_ID';

    /**
     * The stamped identity, or null when this process is not a fleet.
     *
     * Null is not an error state — most processes that run a turn are not
     * fleets — which is why the return is nullable rather than a sentinel
     * string. Callers must treat null as "I cannot prove anything", never as
     * "not mine".
     */
    public static function current(): ?string
    {
        $value = getenv(self::ENV);

        if (false === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * Whether this fleet may clear a claim stamped with $owner.
     *
     * Only a positive match authorizes. Unset-on-either-side and
     * foreign-owner both decline — the first because it proves nothing, the
     * second because a claim held by a fleet that is still running is not
     * abandoned no matter how old it looks.
     */
    public static function mayClearClaimFrom(?string $owner): bool
    {
        $mine = self::current();

        return null !== $mine && $mine === $owner;
    }
}
