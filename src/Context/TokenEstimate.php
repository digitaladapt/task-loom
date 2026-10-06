<?php

declare(strict_types=1);

namespace App\Context;

/**
 * The one conservative characters-per-token estimate the context machinery
 * shares (SPEC §5.6).
 *
 * It lives alone so the two knobs that speak in tokens — the window budget
 * and the input-artifact cap — cannot drift apart: both convert a byte count
 * through this, and a change to one is a change to both.
 *
 * 3.5 rather than the classic 4: this prompt is not prose. It is markdown
 * headers, JSON tool results, key names, uids and other identifiers, all of
 * which the byte-pair tokenizers behind these models split finer than
 * English. Measured against this engine's own compiled output, 4 chars/token
 * under-counts a realistic head by roughly 15–25%, and the estimate exists
 * to fail early, not to be flattering — under-counting does not avoid the
 * model's window, it moves the failure onto the wire as a hard provider
 * error.
 */
final class TokenEstimate
{
    public const float CHARS_PER_TOKEN = 3.5;

    /**
     * The estimated token cost of a run of characters, rounded up: an
     * estimate that rounds down is an estimate that lies in the unsafe
     * direction.
     */
    public static function tokensForChars(int $chars): int
    {
        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }
}
