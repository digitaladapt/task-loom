<?php

declare(strict_types=1);

namespace App\StepModel;

/**
 * A task's step graph failed validation (SPEC §13.2): a cycle, a
 * self-dependency, or a dependency edge that does not point at a step of
 * the same task. Carries every problem found, not just the first.
 */
final class StepGraphException extends \RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Invalid step graph (SPEC §13.2): '.implode(' ', $problems));
    }
}
