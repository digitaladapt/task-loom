<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;

/**
 * A dependency's output that cannot be read (SPEC §13.4): the dependency
 * succeeded on paper but its completion artifact is absent or empty — a
 * corrupt ledger, since the turn cores guarantee a non-empty artifact on
 * success. Typed like ToolboxResolutionException, so the graph layer can
 * fail the dependent child LOUDLY with a classified row instead of
 * compiling a prompt head with silently missing inputs.
 */
final class StepOutputException extends \LogicException
{
    public function __construct(string $message, public readonly ErrorClass $errorClass = ErrorClass::Unknown)
    {
        parent::__construct($message);
    }
}
