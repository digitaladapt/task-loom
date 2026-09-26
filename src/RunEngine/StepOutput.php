<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * One step's output as it flows into a dependent run's prompt head
 * (SPEC §13.4): the step run's justified completion artifact, labeled with
 * the step title. One string — not exchanges, not tool results — frozen at
 * the step's terminal.
 *
 * Step outputs are data, not instructions (§4.2): the compiler frames them
 * accordingly, same untrusted-content rules as tool results.
 */
final readonly class StepOutput
{
    public function __construct(
        public int $stepId,
        public string $title,
        public string $artifact,
    ) {
    }
}
