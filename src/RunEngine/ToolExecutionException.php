<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;

/**
 * A tool call that failed validation or dispatch, already classified
 * for the attempt ledger (SPEC §5.3).
 */
final class ToolExecutionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorClass $errorClass,
    ) {
        parent::__construct($message);
    }
}
