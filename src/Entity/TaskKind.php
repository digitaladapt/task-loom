<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A task's kind — SPEC §9. v1 ships `run` only; `session` is designed-for
 * and deferred to v1.x.
 */
enum TaskKind: string
{
    /** One loop pass: completes with a justified completion declaration. */
    case Run = 'run';

    /** Long-lived recurring work session (v1.x). */
    case Session = 'session';
}
