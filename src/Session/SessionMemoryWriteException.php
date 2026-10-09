<?php

declare(strict_types=1);

namespace App\Session;

/**
 * A session-memory write was refused (docs/design/SESSION_TASKS.md §3.3).
 *
 * Typed — in the spirit of ToolboxResolutionException — so each caller
 * translates it into its own vocabulary: the harness tools (step 3) feed it
 * back to the model as the standard retry path, the UI (step 6) renders it
 * as a field error. The refusal is the same discipline as the tool-result
 * cap on the other side of the head: the store keeps one write bounded, so
 * one unbounded write cannot pin the request floor.
 */
final class SessionMemoryWriteException extends \InvalidArgumentException
{
    public static function blank(): self
    {
        return new self('A session-memory write must not be blank — write the fact you want carried.');
    }

    public static function tooLong(int $chars, int $limit): self
    {
        return new self(\sprintf(
            'Session-memory write refused: %d characters exceeds the %d-character cap (TASKLOOM_SESSION_WRITE_MAX_CHARS). Split it, or keep the essential fact.',
            $chars,
            $limit,
        ));
    }
}
