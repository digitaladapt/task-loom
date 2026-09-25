<?php

declare(strict_types=1);

namespace App\StepModel;

/**
 * The authoring/wire step format was malformed (SPEC §13.2): a missing
 * field, a bad toolbox mode, an empty level, a step that is not an
 * object. Carries every problem found — including the indexed path
 * (steps[1][0].title) — so an authoring agent can fix the input in one
 * pass instead of one error per attempt.
 */
final class StepFormatException extends \RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Invalid steps format: '.implode(' ', $problems));
    }
}
