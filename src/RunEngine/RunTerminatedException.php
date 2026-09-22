<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\ErrorClass;

/**
 * The run loop's deliberate stop: the circuit breaker tripped. Thrown so
 * the loop cannot continue past a classified, repeated failure — caught
 * by run(), which has already marked the run needs_attention.
 *
 * @internal
 */
final class RunTerminatedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorClass $errorClass,
    ) {
        parent::__construct($message);
    }
}
