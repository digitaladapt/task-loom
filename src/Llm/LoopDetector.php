<?php

declare(strict_types=1);

namespace App\Llm;

use App\Context\Utf8;

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
 * Three rules, all stated in characters, all conservative by default:
 *
 *  1. **Block periodicity.** The last `p * minRepeats` characters are `p`
 *     repeated blocks of length `p`, for some `p` from 1 to
 *     `maxPeriodChars`, and the total repeated span is at least
 *     `minSpanChars`. With the defaults (6 repeats, 160-char span), a 27+
 *     character block must repeat 6 times, an 8-character block must
 *     repeat 20 times, and a single character must repeat 160 times —
 *     the shorter the period, the more evidence required. Normal prose
 *     essentially cannot satisfy this; `"ok" x 8` cannot either (span 16).
 *  2. **Long-period recurrence.** The last `hashWindow` characters occur
 *     earlier in the buffer at an offset that makes the tail `p`-periodic
 *     for some `p` **above `maxPeriodChars`**, with at least
 *     `minRecurrenceRepeats` full repetitions and `minSpanChars` of span.
 *     Rule 1 cannot see this by construction: it never tries a period
 *     above its ceiling, and the ceiling is not a judgement about long
 *     loops — it is the cost of rule 1's search (see below). Rule 2 finds
 *     the period by *looking for the tail window again*, which is cheap
 *     regardless of how long the repeated block is, so a model reciting a
 *     paragraph, a whole [you]/[operator] exchange, or a pasted document
 *     is caught at the second or third pass.
 *  3. **Line repetition.** The last `minRepeats` complete lines are equal
 *     once line endings and runs of interior whitespace are normalised
 *     (lines of at least 2 characters). Catches loops whose period is
 *     longer than `hashWindow`, which rules 1 and 2 deliberately do not
 *     chase, and is robust to a loop that varies only in trailing spaces
 *     or line endings (`\n` vs `\r\n`, the documented proxy hazard).
 *
 * ## Why the ceiling in rule 1 is a cost, not a belief
 *
 * Rule 1 sweeps every candidate period and compares character by character,
 * which is quadratic in the period: at `maxPeriodChars = 128` a single check
 * is already ~10^5 byte comparisons, and raising the ceiling to the length
 * the recurrence rule needs would be ~10^7 — re-run every `checkEveryChars`
 * while the model talks. The 128 ceiling is that arithmetic, and the
 * original note's "it will not be tuned until it produces a false positive"
 * understated it: the ceiling does not merely miss *exotic* loops, it misses
 * every loop whose repeated block is longer than the ceiling, which is
 * exactly the shape a model reading its own context produces.
 *
 * Rule 2 is the fix for that, and it costs *less* than rule 1: instead of
 * sweeping periods it asks "has this tail window happened before?", which is
 * one `strpos` over the buffer plus a verification pass on the few
 * candidates. Finding a candidate does not mean believing it — the period it
 * implies is then verified exactly, character by character, over the whole
 * repeated span, so a coincidental window match is rejected rather than
 * reported.
 *
 * ## What a hit is NOT allowed to be
 *
 * The false-positive side is the one that matters most — a detector that
 * cuts real output is worse than no detector — so the rules are deliberately
 * blind to anything that is not a *contiguous, verbatim* repetition:
 *
 *  - Three contiguous verbatim copies of a long block are required by rule 2;
 *    a quoted stanza used twice, a repeated table row, a code block shown
 *    once more at the end, and every "sparse refrain" shape in the tests are
 *    all left alone. (Three verbatim copies is the accepted trade; the
 *    alternative — six — needs a buffer of ~6x the block and lets a 1.7 KB
 *    recitation run for ten minutes.)
 *  - Nothing here is semantic. Paraphrase, restatement and a model circling
 *    an idea in fresh words are all *progress* to this class, by design: a
 *    prose-shape judgement would be a second source of truth about progress,
 *    which the session design refuses (docs/design/SESSION_ENDINGS.md §4).
 *
 * ## Cost
 *
 * The check runs at most once per `checkEveryChars` characters appended, and
 * detection only ever inspects the last `bufferLimit` characters, so a long
 * generation costs the same to watch as a short one. The structural rules
 * are additionally memoised on the tail window: appends that do not change
 * the last `hashWindow` characters — the common case for a token stream,
 * since a delta is a few bytes — leave the rules untouched and only rule 3
 * re-runs, which is linear. This is why `checkEveryChars: 32` against
 * one-token-per-delta streams no longer costs more than the loop it hunts.
 *
 * The `bufferLimit` is the one tuning constant that has to be argued for
 * rather than picked: the longest block rule 2 can see is
 * `(bufferLimit - hashWindow) / minRecurrenceRepeats`, so 16 KB buys ~5.4 KB
 * blocks at three repeats — comfortably past the 1.7 KB unit that prompted
 * this rule. It is set with that arithmetic in mind, and is deliberately not
 * derived from the guard knobs: the buffer is a property of the detector, the
 * repeats are a property of the policy.
 */
final class LoopDetector
{
    public const int BUFFER_LIMIT = 16384;

    /**
     * How much of the tail is used as the "have I seen this before?"
     * fingerprint for rule 2. Long enough to be specific, short enough that
     * a candidate match means the block really is coming round again.
     */
    private const int HASH_WINDOW = 64;

    /**
     * A short prefix of the window used to prefilter candidate offsets, so
     * the expensive per-candidate comparison runs a handful of times rather
     * than once per byte. 16 is long enough that normal text rarely collides
     * and short enough that `strpos` stays the cheap part of the rule.
     */
    private const int PREFILTER_WINDOW = 16;

    /**
     * The cap on candidate offsets verified per check. A pathological buffer
     * (one long run of the prefilter string) must not turn the check
     * quadratic; the nearest candidates are tried first, so the cap costs
     * detection only in cases where the text is already degenerate.
     */
    private const int MAX_CANDIDATES = 512;

    private string $buffer = '';

    private int $sinceCheck = 0;

    private string $lastAnchor = '';

    private int $lastBufferLength = -1;

    private ?LoopHit $lastResult = null;

    public function __construct(
        private readonly int $minSpanChars = 160,
        private readonly int $minRepeats = 6,
        private readonly int $maxPeriodChars = 128,
        private readonly int $checkEveryChars = 32,
        private readonly int $minRecurrenceRepeats = 3,
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
            // Character-safe: a byte-offset trim can leave the buffer starting
            // mid-character, which poisons every preview taken from it.
            $this->buffer = Utf8::tail($this->buffer, self::BUFFER_LIMIT);
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
        // The structural rules depend only on the tail window, so an append
        // that left it unchanged cannot have changed their answer. A token
        // stream appends a few bytes at a time and checks every 32, so this
        // is the common path, and it is what keeps the per-check cost from
        // scaling with the buffer now that the buffer is 16 KB.
        $anchor = strlen($this->buffer) >= self::HASH_WINDOW
            ? substr($this->buffer, -self::HASH_WINDOW)
            : $this->buffer;
        $length = strlen($this->buffer);

        if ($anchor === $this->lastAnchor && $length === $this->lastBufferLength) {
            return $this->lastResult;
        }

        $this->lastAnchor = $anchor;
        $this->lastBufferLength = $length;
        // Cheapest first: the period sweep is bounded by the ceiling, the line
        // rule is one pass over the tail, and the recurrence search is the only
        // one that does substring work. Order also decides which *kind* a hit
        // is reported as when more than one rule could see it, and "the same
        // line N times" is the more specific description of a line loop.
        $this->lastResult = $this->detectBlockPeriodicity()
            ?? $this->detectLineRepetition()
            ?? $this->detectLongPeriodRecurrence();

        return $this->lastResult;
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
                    preview: Utf8::cut(substr($b, $len - $p, $p), $p),
                );
            }
        }

        return null;
    }

    /**
     * Catch a repeated block *longer than rule 1's ceiling*, by finding where
     * the tail window happened before rather than by sweeping periods.
     *
     * The period is only ever a candidate until {@see periodicRun()} has
     * verified it over every character of the span, so a window that matches
     * by coincidence is discarded rather than reported.
     */
    private function detectLongPeriodRecurrence(): ?LoopHit
    {
        $b = $this->buffer;
        $len = strlen($b);

        if ($len < self::HASH_WINDOW + $this->minSpanChars) {
            return null;
        }

        if ($len < $this->minRecurrenceRepeats * ($this->maxPeriodChars + 1)) {
            // Not enough room for `minRecurrenceRepeats` repetitions of a
            // block above the rule-1 ceiling: nothing this rule could see can
            // exist yet, so it does not pay for the search.
            return null;
        }

        $anchorStart = $len - self::HASH_WINDOW;
        $anchor = substr($b, $anchorStart, self::HASH_WINDOW);

        // Only offsets that would imply a period above rule 1's ceiling are
        // of interest — rule 1 owns everything at or below it. The *uppermost*
        // of those offsets is the smallest admissible period, so it is the
        // bound the search is allowed to give up at.
        $nearestOffset = $anchorStart - ($this->maxPeriodChars + 1);

        $needle = substr($b, $anchorStart, self::PREFILTER_WINDOW);
        $candidates = [];
        $offset = 0;
        while (\count($candidates) < self::MAX_CANDIDATES) {
            $pos = strpos($b, $needle, $offset);
            if (false === $pos || $pos > $nearestOffset) {
                break;
            }
            $candidates[] = $pos;
            $offset = $pos + 1;
        }

        // `strpos` returns hays·tack order, so the *nearest* offset is the last
        // found: walk backwards to try the smallest admissible period first,
        // because a tight loop is both the likelier finding and the cheaper
        // verification.
        for ($i = \count($candidates) - 1; $i >= 0; --$i) {
            $period = $anchorStart - $candidates[$i];
            if ($period <= $this->maxPeriodChars) {
                continue;
            }

            $run = $this->periodicRun($period);
            $span = $run[0];
            $repeats = $run[1];

            if ($repeats >= $this->minRecurrenceRepeats && $span >= $this->minSpanChars) {
                return new LoopHit(
                    kind: 'recurrence',
                    periodChars: $period,
                    repeats: $repeats,
                    spanChars: $span,
                    // The window is in bytes and may end inside a character:
                    // cut() trims the split off rather than letting a preview
                    // of 120 bytes or fewer slip past describe()'s 40-byte cap
                    // while still being malformed.
                    preview: Utf8::cut(substr($b, $candidates[$i], min($period, 120)), min($period, 120)),
                );
            }
        }

        return null;
    }

    /**
     * How far the tail is exactly periodic with the given period, as
     * [spanChars, repeats]. Verifying character by character is what makes a
     * candidate a finding: the window search only proposes periods.
     *
     * @return array{0: int, 1: int}
     */
    private function periodicRun(int $period): array
    {
        $b = $this->buffer;
        $len = strlen($b);

        $start = $len - 1;
        while ($start - $period >= 0 && $b[$start] === $b[$start - $period]) {
            --$start;
        }

        $span = $len - $start;

        return [$span, intdiv($span, $period)];
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

        // A loop that varies only in line endings, trailing whitespace or the
        // number of spaces between words is still a loop; normalising those
        // away makes this rule robust to a proxy rewriting the stream without
        // widening it to anything that is not the same line. Interior runs are
        // collapsed rather than stripped so `| a | 1 |` does not become
        // `|a|1|` — the rule stays about the line, not a mangled form of it.
        $normalise = static function (string $line): string {
            $line = rtrim($line, "\r\n\t ");

            return (string) preg_replace('/[ \t]+/', ' ', $line);
        };

        $last = $normalise($lines[$count - 1]);
        if (strlen($last) < 2) {
            return null;
        }

        $repeats = 1;
        for ($i = $count - 2; $i >= 0 && $normalise($lines[$i]) === $last; --$i) {
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
