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
 * Units are deployment-wide too (`TASKLOOM_UNITS`): the model reads this
 * once, at the head of the run, and reports in those units throughout.
 *
 * Both knobs are validated at construction — a typo fails loudly, naming
 * its variable, rather than quietly pinning the deployment to a default the
 * operator did not choose. This is the same refusal the scheduler makes:
 * there is no safe guess about the operator's clock or their units.
 */
final readonly class Grounding
{
    private \DateTimeZone $timezone;
    private Units $units;

    /**
     * @param string              $timezone the IANA name the deployment runs in (TASKLOOM_TIMEZONE)
     * @param ?string             $location free-text place the operator is in; omitted when null
     * @param ?string             $units    "metric" or "imperial" (TASKLOOM_UNITS; unset → metric)
     * @param ?\DateTimeImmutable $now      fixed clock for tests; a real clock otherwise
     */
    public function __construct(
        string $timezone,
        private ?string $location = null,
        ?string $units = null,
        private ?\DateTimeImmutable $now = null,
    ) {
        $this->timezone = self::resolveTimezone($timezone);
        $this->units = Units::fromConfig($units);
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
}
