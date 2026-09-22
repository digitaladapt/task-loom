<?php

declare(strict_types=1);

namespace App\Context;

use App\Entity\ErrorClass;

/**
 * The pruned window cannot fit the fail-closed context budget (SPEC §5.6).
 */
final class ContextExhaustedException extends \RuntimeException
{
    public readonly ErrorClass $errorClass;

    public function __construct(string $message)
    {
        parent::__construct($message);
        $this->errorClass = ErrorClass::ContextExhausted;
    }
}
