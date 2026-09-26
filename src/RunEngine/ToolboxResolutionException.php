<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;

/**
 * A toolbox declaration that cannot resolve (SPEC §4.1): a tool that is not
 * in the catalog, or a declaration that resolves to nothing. Still a
 * \LogicException — the resolver's contract is unchanged — but typed, so
 * the graph layer can fail a child run LOUDLY with the right error class
 * (SPEC §5.3, §13.5) instead of letting the throw escape a terminal
 * commit's transaction.
 */
final class ToolboxResolutionException extends \LogicException
{
    public function __construct(string $message, public readonly ErrorClass $errorClass = ErrorClass::ToolNotFound)
    {
        parent::__construct($message);
    }
}
