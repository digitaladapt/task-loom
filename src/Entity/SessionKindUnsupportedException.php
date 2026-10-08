<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A session-kind task was refused because the slice engine behind the kind
 * does not exist yet (docs/design/SESSION_TASKS.md, build order step 1).
 *
 * `TaskKind::Session` has existed since v1 and `TaskCrud::coerceKind()`
 * accepted it, but nothing branched on kind — a task persisted as
 * `kind: "session"` would be dispatched as an ordinary single-pass run
 * wearing a session's label. Until the engine lands, every gate refuses the
 * kind instead: the write path (create/update), enable/approve, the editor
 * parser, and the engine's dispatch entry points — each refusal naming the
 * reason, so the failure is loud rather than a silently mis-run task.
 *
 * Typed in the spirit of ToolboxResolutionException, so each caller
 * translates it into its own vocabulary: a tool error for MCP, a field
 * error for the editor, a lifecycle refusal for the admin UI, a classified
 * ledger row for the scheduler. Temporary: it goes away with the first
 * working slice.
 */
final class SessionKindUnsupportedException extends \LogicException
{
    public function __construct(
        string $message,
        public readonly ErrorClass $errorClass = ErrorClass::Unknown,
    ) {
        parent::__construct($message);
    }

    /**
     * The refusal as one sentence, for callers that render it rather than
     * let it escape (the editor's field error, the lifecycle flash message).
     *
     * @param string $situation a full clause, e.g. 'Cannot create a "session"-kind task'
     */
    public static function sentence(string $situation): string
    {
        return \sprintf(
            '%s — the slice engine behind the kind is not built yet (docs/design/SESSION_TASKS.md, build order step 1); dispatched today, a session-kind task would run as an ordinary single-pass task wearing a session\'s label.',
            $situation,
        );
    }

    public static function refused(string $situation): self
    {
        return new self(self::sentence($situation));
    }
}
