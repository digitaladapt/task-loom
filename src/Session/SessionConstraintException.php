<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\ErrorClass;

/**
 * A session-kind constraint was violated (docs/design/SESSION_TASKS.md §7,
 * §8, build order step 4) — one of the rules that outlived the blanket
 * kind-gate.
 *
 * Until step 4, `SessionKindUnsupportedException` refused the kind
 * wholesale, because the engine behind it did not exist. The engine exists
 * now, and the refusals that remain are the design's own: a session is
 * lane-driven (never the synchronous path), it runs one slice at a time, it
 * cannot have steps (a slice is already its unit of work, and step children
 * are never handed the harness tools — a stepped session could never
 * declare), it cannot be scheduled (it is started and stopped, not cron'd),
 * and a `done` session stays done until a new version replaces it.
 *
 * Typed so each caller translates it into its own vocabulary — a tool error
 * for MCP, a field error for the editor, a lifecycle refusal for the admin
 * UI, a dispatch refusal for the CLI, a classified ledger row for the
 * scheduler — the same discipline the step graph and the schedule already
 * follow. Carries an error class for the callers that record rather than
 * render it (the scheduler's classified dispatch failure).
 */
final class SessionConstraintException extends \LogicException
{
    public function __construct(
        string $message,
        public readonly ErrorClass $errorClass = ErrorClass::Unknown,
    ) {
        parent::__construct($message);
    }

    public static function syncNotAllowed(): self
    {
        return new self('A session\'s slices are driven by the worker lanes — dispatch with --queue (or Run now), never synchronously (docs/design/SESSION_TASKS.md §7).');
    }

    public static function sliceInFlight(): self
    {
        return new self('A slice of this session is already in flight — a session runs one slice at a time (docs/design/SESSION_TASKS.md §8).');
    }

    public static function complete(): self
    {
        return new self('The session is complete — its last slice declared "done"; a new session (or an approved replacement) is how the work continues (docs/design/SESSION_TASKS.md §8).');
    }

    public static function paused(): self
    {
        return new self('This session is paused — enable it to launch a slice (docs/design/SESSION_TASKS.md §8).');
    }

    public static function stepsNotAllowed(): self
    {
        return new self('A session cannot have steps — a slice is already the session\'s unit of work, and a step\'s run is never handed the harness tools, so a stepped session could never declare (docs/design/SESSION_TASKS.md §8).');
    }

    public static function scheduleNotAllowed(): self
    {
        return new self('A session cannot be scheduled — it is started and stopped, not cron\'d; an enabled session re-slices itself until it declares done or blocked (docs/design/SESSION_TASKS.md §8).');
    }
}
