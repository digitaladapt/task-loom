<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * One detected repetition in a stream of model output.
 *
 * `kind` is 'block' when a repeated substring of `periodChars` was found
 * (the common degenerate loop — the model re-emitting a phrase, a JSON
 * fragment, a whole line of tokens) or 'line' when whole newline-delimited
 * lines repeat identically (a loop whose period is longer than the block
 * detector's ceiling, or one that is only visible line-by-line).
 *
 * `spanChars` is how many trailing characters the repetition covers — the
 * amount of output that was, conservatively, evidence of the loop. That is
 * the number an operator wants ("it was stuck re-emitting the same 12
 * characters for 300 chars before we stopped it").
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
        if (strlen($preview) > 40) {
            $preview = substr($preview, 0, 40).'…';
        }

        return match ($this->kind) {
            'line' => sprintf('the same line %d times (%d chars, "%s")', $this->repeats, $this->spanChars, $preview),
            default => sprintf('a %d-character block %d times (%d chars, "%s")', $this->periodChars, $this->repeats, $this->spanChars, $preview),
        };
    }
}
