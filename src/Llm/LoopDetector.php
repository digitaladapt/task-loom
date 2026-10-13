<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Watches a growing stream of model output for degenerate repetition.
 *
 * A local model that "spins forever" is usually not silent: it is happily
 * re-emitting the same tokens, and the operator cannot see it because the
 * output was never captured until the timeout. This detector is the reason
 * the capture exists — it runs per delta, over the *suffix* of what has
 * been produced, and fires when the output has demonstrably started
 * repeating rather than merely echoing (a doubled word, a quoted line, a
 * table of similar rows must all pass untouched).
 *
 * Two rules, both stated in characters, both conservative by default:
 *
 *  1. **Block periodicity.** The last `p * minRepeats` characters are `p`
 *     repeated blocks of length `p`, for some `p` from 1 to
 *     `maxPeriodChars`, and the total repeated span is at least
 *     `minSpanChars`. With the defaults (6 repeats, 160-char span), a 27+
 *     character block must repeat 6 times, an 8-character block must
 *     repeat 20 times, and a single character must repeat 160 times —
 *     the shorter the period, the more evidence required. Normal prose
 *     essentially cannot satisfy this; `"ok" x 8` cannot either (span 16).
 *  2. **Line repetition.** The last `minRepeats` complete lines are
 *     byte-identical (lines of at least 2 characters). Catches loops
 *     whose period is longer than `maxPeriodChars`, which rule 1
 *     deliberately does not chase.
 *
 * The check runs at most once per `checkEveryChars` characters appended,
 * so the per-delta cost is amortized and bounded; detection only ever
 * inspects the last 8 KB, so a long generation costs the same to watch as
 * a short one.
 *
 * This is a heuristic and is documented as one: it can miss exotic loops
 * (it will not be tuned until it produces a false positive), and the stop
 * it justifies is always recorded with its evidence (what repeated, how
 * often, over how many characters) so "was this a loop or a bad call" is
 * answerable after the fact.
 */
final class LoopDetector
{
    private const int BUFFER_LIMIT = 8192;

    private string $buffer = '';

    private int $sinceCheck = 0;

    public function __construct(
        private readonly int $minSpanChars = 160,
        private readonly int $minRepeats = 6,
        private readonly int $maxPeriodChars = 128,
        private readonly int $checkEveryChars = 32,
    ) {
    }

    /**
     * Append a delta; return a hit if the output now looks stuck.
     */
    public function observe(string $delta): ?LoopHit
    {
        if ('' === $delta) {
            return null;
        }

        $this->buffer .= $delta;
        if (strlen($this->buffer) > self::BUFFER_LIMIT) {
            $this->buffer = substr($this->buffer, -self::BUFFER_LIMIT);
        }

        $this->sinceCheck += strlen($delta);
        if ($this->sinceCheck < $this->checkEveryChars) {
            return null;
        }
        $this->sinceCheck = 0;

        return $this->detect();
    }

    public function bufferLength(): int
    {
        return strlen($this->buffer);
    }

    private function detect(): ?LoopHit
    {
        return $this->detectBlockPeriodicity() ?? $this->detectLineRepetition();
    }

    private function detectBlockPeriodicity(): ?LoopHit
    {
        $b = $this->buffer;
        $len = strlen($b);

        if ($len < $this->minSpanChars) {
            return null;
        }

        $pMax = min($this->maxPeriodChars, intdiv($len, $this->minRepeats));

        for ($p = 1; $p <= $pMax; ++$p) {
            $windowStart = $len - $p * $this->minRepeats;

            $ok = true;
            for ($i = $windowStart + $p; $i < $len; ++$i) {
                if ($b[$i] !== $b[$i - $p]) {
                    $ok = false;
                    break;
                }
            }

            if (!$ok) {
                continue;
            }

            // Extend the periodic region backwards, one character at a time:
            // a character before the window keeps the periodicity if it
            // equals the character one period to its right.
            $start = $windowStart;
            while ($start - 1 >= 0 && $b[$start - 1] === $b[$start - 1 + $p]) {
                --$start;
            }

            $span = $len - $start;
            $repeats = intdiv($span, $p);

            if ($repeats >= $this->minRepeats && $span >= $this->minSpanChars) {
                return new LoopHit(
                    kind: 'block',
                    periodChars: $p,
                    repeats: $repeats,
                    spanChars: $span,
                    preview: substr($b, $len - $p, $p),
                );
            }
        }

        return null;
    }

    private function detectLineRepetition(): ?LoopHit
    {
        $lines = explode("\n", $this->buffer);

        // Only complete lines count: if the buffer does not end on a
        // newline, the last element is a partial line torn off mid-write.
        if ('' !== end($lines)) {
            array_pop($lines);
        } else {
            array_pop($lines); // the trailing empty element after the final "\n"
        }

        $count = count($lines);
        if ($count < $this->minRepeats) {
            return null;
        }

        $last = $lines[$count - 1];
        if (strlen($last) < 2) {
            return null;
        }

        $repeats = 1;
        for ($i = $count - 2; $i >= 0 && $lines[$i] === $last; --$i) {
            ++$repeats;
        }

        if ($repeats < $this->minRepeats) {
            return null;
        }

        return new LoopHit(
            kind: 'line',
            periodChars: strlen($last),
            repeats: $repeats,
            spanChars: $repeats * strlen($last),
            preview: $last,
        );
    }
}
