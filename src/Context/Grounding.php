<?php

declare(strict_types=1);

namespace App\Context;

/**
 * The grounding block (SPEC §4.2 / §5.6): harness-authored, identical in
 * shape every run — date, time, timezone, units, optional location.
 * Global config, never per-task, never tool-influenced.
 *
 * It reports the deployment's clock. `TASKLOOM_TIMEZONE` decides both the
 * wall-clock time the block states and the zone it names, so a run and the
 * schedules that launch it agree on what time it is: a Chicago deployment
 * reads "09:15 (America/Chicago)", never "09:15 (UTC)".
 *
 * Units (`TASKLOOM_UNITS`) and location (`TASKLOOM_LOCATION`) are
 * deployment-wide too: the model reads this once, at the head of the run,
 * and reports in those units, from that place, throughout. Location is
 * omitted from the block when unset — a deployment that has not said where
 * it is must not be told a location, and must not be handed an empty
 * "Location:" line that reads like a rendering bug.
 *
 * Every knob is validated at construction — a typo fails loudly, naming its
 * variable, rather than quietly pinning the deployment to a default the
 * operator did not choose. This is the same refusal the scheduler makes:
 * there is no safe guess about the operator's clock, their units, or where
 * they are.
 */
final readonly class Grounding
{
    private \DateTimeZone $timezone;
    private Units $units;
    private ?string $location;

    /**
     * @param string              $timezone the IANA name the deployment runs in (TASKLOOM_TIMEZONE)
     * @param ?string             $location free-text place the operator is in (TASKLOOM_LOCATION); omitted when unset or blank
     * @param ?string             $units    "metric" or "imperial" (TASKLOOM_UNITS; unset → metric)
     * @param ?\DateTimeImmutable $now      fixed clock for tests; a real clock otherwise
     */
    public function __construct(
        string $timezone,
        ?string $location = null,
        ?string $units = null,
        private ?\DateTimeImmutable $now = null,
    ) {
        $this->timezone = self::resolveTimezone($timezone);
        $this->units = Units::fromConfig($units);
        $this->location = self::resolveLocation($location);
    }

    /**
     * This deployment's grounding, fixed to a stated instant.
     *
     * The block is normally rendered from the live clock, which is right for a
     * run (short, and its head is frozen anyway) but wrong for a conversation:
     * a chat re-renders the block on every reply, and a prefix-caching server
     * reuses a request only up to the first byte that changed. A clock at
     * position 0 of the system message therefore costs the *whole* request on
     * every minute boundary — measured at 28% prefix reuse against 94% when
     * the block does not move (`docs/design/KV_CACHE_POSITION_FINDINGS.md`).
     *
     * Freezing is how a caller gets the freshness the deployment *chose* (the
     * block still states the deployment's zone, units and location) without
     * paying a cache break per reply: the instant is captured once and the
     * block is byte-identical on every request until the caller chooses to
     * move it (see {@see \App\Entity\Chat::groundingInstant()}).
     */
    public function forNow(\DateTimeImmutable $now): self
    {
        return new self(
            timezone: $this->timezone->getName(),
            location: $this->location,
            units: $this->units->value,
            now: $now,
        );
    }

    /**
     * The deployment-local calendar day of an instant, as `Y-m-d`.
     *
     * A caller that freezes the block wants to know when the freeze has gone
     * stale — and "stale" is the deployment's day changing, not the model's or
     * the container's. Comparing this across two instants is how a frozen
     * conversation decides to re-ground (`\App\Chat\ChatEngine`).
     */
    public function calendarDay(\DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function render(): string
    {
        // Always normalize to the deployment's zone, whatever zone the clock
        // was born in: the block's contract is "the time where the operator
        // lives", and an injected test clock does not get to change that.
        $now = ($this->now ?? new \DateTimeImmutable('now'))->setTimezone($this->timezone);

        $lines = [
            'Current date: '.$now->format('l, F j, Y'),
            'Current time: '.$now->format('H:i').' ('.$now->format('e').')',
            'Units: '.$this->units->value,
        ];

        if (null !== $this->location) {
            $lines[] = 'Location: '.$this->location;
        }

        return implode("\n", $lines);
    }

    /**
     * @throws \InvalidArgumentException when the name is not an IANA timezone identifier
     */
    private static function resolveTimezone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('TASKLOOM_TIMEZONE is not a valid timezone identifier: "%s". Use an IANA name such as America/Chicago or UTC.', $timezone));
        }
    }

    /**
     * Trim, treat blank as unset, and refuse a value that spans lines.
     *
     * The block's promise is a fixed shape — one line per fact. A newline in
     * the location would break that promise and let a single knob forge what
     * read like additional harness-authored lines ("Location: Nowhere
     * Units: imperial"). Location is operator config, not untrusted input,
     * so this is about the shape guarantee rather than an attack — but the
     * guarantee is worth more than the fifty cents it costs to keep.
     *
     * @throws \InvalidArgumentException when the value spans more than one line
     */
    private static function resolveLocation(?string $location): ?string
    {
        if (null === $location) {
            return null;
        }

        $trimmed = trim($location);
        if ('' === $trimmed) {
            return null;
        }

        if (preg_match('/[\r\n]/', $trimmed)) {
            throw new \InvalidArgumentException('TASKLOOM_LOCATION must be a single line: the grounding block renders it as one "Location:" line, and a newline would break the block\'s shape.');
        }

        return $trimmed;
    }
}
