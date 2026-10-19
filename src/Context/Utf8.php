<?php

declare(strict_types=1);

namespace App\Context;

/**
 * The one place a string is cut by a byte budget without splitting a UTF-8
 * character in half.
 *
 * `substr()` counts bytes. A budget expressed in bytes is what the context
 * caps want — a token estimate is a function of bytes, and a byte count is
 * what keeps a cap honest — but cutting at a byte offset lands wherever it
 * lands, and if a multi-byte character straddles that offset the result ends
 * in a partial sequence. That string is no longer valid UTF-8, and
 * `json_encode()` refuses it with JSON_ERROR_UTF8, which DBAL reports as
 * `SerializationFailed` — the exact shape of "Could not convert PHP type
 * "array" to "json" … Malformed UTF-8 characters" seen when a capped tool
 * result was written to a `json` column (chat_exchange_event.payload).
 *
 * The failure is invisible to any test whose fixture is ASCII: there the byte
 * offset and the character offset agree, so the cut can never split anything.
 * It appears in production, on the first tool result whose 17203rd byte falls
 * inside an ellipsis, an em dash or a box-drawing glyph — which is most of
 * them.
 *
 * So every cap goes through here. The budget keeps its meaning (at most
 * $maxBytes bytes out, measured the same way as before), and the cut simply
 * backs up to the start of the character it would have split — losing at most
 * three bytes, always producing valid UTF-8.
 *
 * Deliberately extension-light. `\strlen()` measures bytes and `substr()` cuts
 * them, so the boundary arithmetic needs nothing; validation is one PCRE call
 * with the `u` modifier. `mb_strcut()` would be the obvious tool, but it is not
 * guaranteed: `symfony/polyfill-mbstring` implements mb_check_encoding and
 * mb_scrub and *not* mb_strcut, so on a host without php-mbstring the function
 * would simply not exist — and the cap is not a place to discover that. Only
 * {@see scrub()} reaches for an extension, preferring mbstring and falling back
 * to `ext-iconv`, which composer does require.
 */
final class Utf8
{
    /**
     * At most $maxBytes bytes of $text, cut on a character boundary.
     *
     * Never returns a longer string, and never returns the tail end of a
     * character: if the budget lands mid-character, that character is dropped
     * whole.
     *
     * Note this validates a string that already fits rather than returning it
     * untouched. A caller that has just taken a fixed-size byte window (the
     * loop detector takes `substr($b, $offset, $n)`) hands over a string that
     * is exactly the budget and may already end mid-character; "it fits, so
     * leave it" would let exactly the case this helper exists for through.
     */
    public static function cut(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        $cut = \strlen($text) <= $maxBytes ? $text : substr($text, 0, $maxBytes);

        if (self::isValid($cut)) {
            return $cut;
        }

        // Walk back over a split character. A UTF-8 continuation byte is
        // 10xxxxxx; a valid prefix cannot end on one, and dropping at most
        // three of them reaches the start of the character that was split.
        //
        // The bound is load-bearing, not defensive: if $text is already
        // malformed *earlier* than the cut, no amount of trimming the end will
        // ever make it validate, and an unbounded `while (!self::isValid())`
        // would strip the whole string to empty — one full-string PCRE check
        // per byte, which is quadratic on a 17 KB tool result. Three steps
        // fixes what is fixable and leaves an already-broken input alone.
        for ($dropped = 0; $dropped < 3 && '' !== $cut && !self::isValid($cut); ++$dropped) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /**
     * At most $maxBytes bytes of $text *from the end*, cut on a character
     * boundary.
     *
     * The mirror of {@see cut()} for the bounded buffers that keep a trailing
     * window (the loop detector's 16 KB buffer, a stream's tail). A negative
     * `substr()` offset counts bytes from the end just as an offset counts from
     * the start, so it splits a character in exactly the same way — and here
     * the damage is worse, because the split sits at the *beginning* of the
     * buffer, where every subsequent preview is taken from.
     */
    public static function tail(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        $cut = \strlen($text) <= $maxBytes ? $text : substr($text, -$maxBytes);

        if (self::isValid($cut)) {
            return $cut;
        }

        // Walk forward off the split character: a valid UTF-8 string cannot
        // begin with a continuation byte, and at most three of them are in the
        // way. Bounded for the same reason as cut(): an unbounded walk would
        // eat a whole buffer whose damage is elsewhere.
        for ($dropped = 0; $dropped < 3 && '' !== $cut && !self::isValid($cut); ++$dropped) {
            $cut = substr($cut, 1);
        }

        return $cut;
    }

    /**
     * Whether $text is well-formed UTF-8.
     *
     * The `u` modifier makes PCRE reject malformed subject strings, so an
     * empty pattern is a cheap whole-string validation.
     */
    public static function isValid(string $text): bool
    {
        return 1 === preg_match('//u', $text);
    }

    /**
     * Replace malformed UTF-8 sequences with U+FFFD.
     *
     * The backstop for text that did not come through a cap: a broken byte
     * out of an upstream stream or an MCP server should degrade to a
     * replacement character, not take down a live reply inside the
     * persistence layer with an opaque serialization error.
     */
    public static function scrub(string $text): string
    {
        if (self::isValid($text)) {
            return $text;
        }

        if (\function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }

        return (string) iconv('UTF-8', 'UTF-8//IGNORE', $text);
    }
}
