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

    /**
     * Whether the engine behind this kind exists yet. v1 ships `run` only:
     * `session` is refused at every gate — the write path, enable/approve,
     * and dispatch — until its slice engine lands (docs/design/
     * SESSION_TASKS.md, build order step 1). When it does, this predicate
     * and its callers go away together, in one reviewable change.
     */
    public function isImplemented(): bool
    {
        return self::Run === $this;
    }
}
