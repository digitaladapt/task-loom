<?php

declare(strict_types=1);

namespace App\Llm;

use App\Context\Utf8;

/**
 * One detected repetition in a stream of model output.
 *
 * `kind` names the rule that found it, so the ledger entry says *which* shape
 * of loop this was:
 *
 *  - 'block' — a repeated substring of `periodChars` at or below the block
 *    detector's period ceiling (the classic degenerate loop: the model
 *    re-emitting a phrase, a JSON fragment, a short line of tokens).
 *  - 'recurrence' — a repeated substring *longer* than that ceiling, found by
 *    the tail-window search rather than the period sweep. This is the shape a
 *    model reading its own context produces — a whole exchange, a paragraph,
 *    a pasted document recited again verbatim — and it is what the ceiling
 *    used to make invisible.
 *  - 'line' — whole newline-delimited lines repeat identically (normalised
 *    for line endings and interior whitespace): a loop whose period is
 *    longer than the recurrence window, or one that is only visible
 *    line-by-line.
 *
 * `spanChars` is how many trailing characters the repetition covers — the
 * amount of output that was, conservatively, evidence of the loop. That is
 * the number an operator wants ("it was stuck re-emitting the same 1.7 KB
 * block for 11 KB before we stopped it").
 */
final readonly class LoopHit
{
    public function __construct(
        public string $kind,
        public int $periodChars,
        public int $repeats,
        public int $spanChars,
        public string $preview,
    ) {
    }

    /**
     * One line for the ledger and the log.
     */
    public function describe(): string
    {
        $preview = str_replace(["\n", "\r", "\t"], ' ', $this->preview);
        // 40 bytes, cut on a character boundary: this string is spliced into
        // LlmStop::detail, which is persisted in the llm_response event
        // payload, so a split character fails the write.
        if (\strlen($preview) > 40) {
            $preview = Utf8::cut($preview, 40).'…';
        }

        return match ($this->kind) {
            'line' => sprintf('the same line %d times (%d chars, "%s")', $this->repeats, $this->spanChars, $preview),
            'recurrence' => sprintf('a %d-character block repeated %d times at long range (%d chars, "%s")', $this->periodChars, $this->repeats, $this->spanChars, $preview),
            default => sprintf('a %d-character block %d times (%d chars, "%s")', $this->periodChars, $this->repeats, $this->spanChars, $preview),
        };
    }
}
