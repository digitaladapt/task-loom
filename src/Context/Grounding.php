<?php

declare(strict_types=1);

namespace App\Context;

/**
 * The grounding block (SPEC §4.2 / §5.6): harness-authored, identical in
 * shape every run — date, time, timezone, units, optional location.
 * Global config, never per-task, never tool-influenced.
 */
final readonly class Grounding
{
    public function __construct(
        private ?string $location = null,
        private string $units = 'metric',
        private ?\DateTimeImmutable $now = null,
    ) {
    }

    public function render(): string
    {
        $now = $this->now ?? new \DateTimeImmutable('now');

        $lines = [
            'Current date: '.$now->format('l, F j, Y'),
            'Current time: '.$now->format('H:i').' ('.$now->format('e').')',
            'Units: '.$this->units,
        ];

        if (null !== $this->location) {
            $lines[] = 'Location: '.$this->location;
        }

        return implode("\n", $lines);
    }
}
