<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * Whether the process asking is entitled to reap execution claims
 * (SPEC §6.2).
 *
 * A claim cleared on the strength of "we are booting, so nobody can be
 * working" is only true of the process group that is being restarted
 * together. It is not true of the run table. Two cases in this repo alone
 * break it: the one-shot `migrate` service, which never touches the run
 * table; and any process sharing a database with a host that is still
 * running workers.
 *
 * So the authority is granted explicitly, by the process that starts the
 * fleet, rather than inferred from "I am running". `docker/entrypoint.sh`
 * sets TASKLOOM_FLEET_OWNER=1 in its `serve` path — the only path that
 * spawns workers — and the sweep honours the flag only when it is set.
 *
 * **This flag is deliberately absent from the deployment env contract.**
 * Listing it in a compose file would be actively wrong: compose services
 * share an environment anchor, so a flag that says "I start workers" would be
 * handed to the one-shot `migrate` service as well — the very process the
 * gate exists to exclude. It is set by the entrypoint, from knowledge the
 * entrypoint actually has, and reached here through fromProcessEnv().
 *
 * **The parse fails closed, and says so.** An unrecognized value (a typo,
 * `yes`, a stray space) is treated as "not an owner", because the dangerous
 * direction is claiming authority you do not have: a missed reap costs one
 * hour of waiting, whereas an unearned reap can cancel live work. But it is
 * not silent — parse() returns null so the caller can name the bad value and
 * the accepted ones. A deployment must not be able to believe a sweep is
 * armed when it is not.
 */
enum FleetOwnership
{
    /** The env var the entrypoint sets in the fleet-owning process group. */
    public const string ENV = 'TASKLOOM_FLEET_OWNER';

    /** Values that grant fleet ownership, compared case-insensitively. */
    private const array AFFIRMATIVE = ['1', 'true', 'on'];

    /**
     * Values that plainly mean "no", compared case-insensitively.
     *
     * These are deliberate negatives, not mistakes: an operator who writes
     * `off` has made a decision, and warning at them for it would train them
     * to ignore the warning that matters. Only a value outside both lists is
     * Unrecognized — `yes` belongs there, which is the whole point: it is the
     * word someone writes when they mean the opposite of what it does.
     */
    private const array NEGATIVE = ['0', 'false', 'off', 'no'];

    case Owner;

    case NotOwner;

    /** An unrecognized value: treated as "not owner", but worth reporting. */
    case Unrecognized;

    /**
     * Parse the raw environment value.
     *
     * Unset or empty is a plain "not an owner" — that is the ordinary case
     * for a one-shot container, not a mistake. A non-empty value that is not
     * one of the affirmative spellings is Unrecognized, and the caller is
     * expected to say so out loud.
     */
    /**
     * Read the flag from the process environment — the seam between "a shell
     * exported this" and the pure parse() above.
     *
     * getenv() rather than $_SERVER because this is process state, not a
     * request variable: the same reason the value is not a container
     * parameter (see the class docblock). getenv() returns false when unset,
     * which is the ordinary "not the owner" case.
     */
    public static function fromProcessEnv(): self
    {
        $value = getenv(self::ENV);

        return self::parse(false === $value ? null : $value);
    }

    public static function parse(?string $value): self
    {
        if (null === $value || '' === trim($value)) {
            return self::NotOwner;
        }

        $normalized = strtolower(trim($value));

        if (\in_array($normalized, self::AFFIRMATIVE, true)) {
            return self::Owner;
        }

        return \in_array($normalized, self::NEGATIVE, true)
            ? self::NotOwner
            : self::Unrecognized;
    }

    /**
     * Whether the sweep may proceed. Unknown values do not grant authority.
     */
    public function ownsFleet(): bool
    {
        return self::Owner === $this;
    }

    /**
     * The accepted spellings, for an error message that tells the operator
     * what to write instead.
     *
     * @return list<string>
     */
    public static function acceptedValues(): array
    {
        return self::AFFIRMATIVE;
    }

    /**
     * The spellings that plainly mean "no" and therefore do not warrant a
     * warning. Exposed for the same reason as acceptedValues(): a test can
     * pin that the advertised negatives do not trip the unrecognized path.
     *
     * @return list<string>
     */
    public static function negativeValues(): array
    {
        return self::NEGATIVE;
    }
}
