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
 * Note carefully what the sweep does NOT do with this value, because a first
 * attempt tried to and it cannot work. "A restart has a new identity, so a
 * claim stamped with the old one is a dead predecessor's, and can be cleared at
 * any age" sounds compelling and fails completely: *every* restart is a new
 * identity, so every leftover claim from the previous run is "another fleet",
 * and the sweep declines all of them by construction. The bug was visible in
 * the very test written to prove the mechanism, which passed the same id on
 * both sides — a situation that never occurs in production. A per-start value
 * can say "not me"; it can never say "me, from last time". The sweep decides
 * on what could still be *in flight* instead; see ClaimReaper.
 *
 * ## Where the identity comes from
 *
 * The entrypoint generates it, because the entrypoint is what knows a fleet is
 * being started: `TASKLOOM_FLEET_ID` is set to a fresh value per container
 * start. It is deliberately **not** part of the compose env contract — compose
 * services share an environment anchor, so a fixed value there would be one
 * label for every service and every restart. The entrypoint also writes it to
 * the data volume, so a process that shares the container but not its
 * environment (the web UI) can still attribute a claim to this fleet.
 *
 * Same family as FleetOwnership, different question: that class decides whether
 * a process may sweep at all, this one says who the process is once it does.
 *
 * ## Absence is normal and is not an error
 *
 * Unset is the ordinary case for anything that is not the entrypoint's child —
 * a manual console command, a cron job, a one-shot `app:run:now`. The value is
 * a label, not a credential: knowing it authorizes nothing, and lacking it
 * prevents nothing except attribution. Code that needs to *decide* something
 * must not read this; code that needs to *explain* something may.
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
     * Whether a claim's label is this fleet's own.
     *
     * FOR EXPLANATION ONLY — never for authorizing a sweep. That is the whole
     * lesson of this class: a method like this reads so much like a permission
     * check that the first version of the reaper used it as one, and the result
     * was a sweep that reported "held by another fleet" for every claim in the
     * table, because on a restart *every* leftover claim is labelled with the
     * previous start's id. It answers "did I write this?", and the question
     * that actually matters at boot is "could anyone still be holding this?"
     * — a different question with a different answer (ClaimReaper).
     */
    public static function isOurs(?string $owner): bool
    {
        $mine = self::current();

        return null !== $mine && $mine === $owner;
    }
}
