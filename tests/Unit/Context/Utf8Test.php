<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\Utf8;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The regression suite for the bug the ASCII fixtures could not see.
 *
 * "Could not convert PHP type "array" to "json" … Malformed UTF-8 characters"
 * was not a JSON problem: a byte budget was spent with substr(), the cut
 * landed inside a multi-byte character, and json_encode refused the result.
 * Every pre-existing cap test fed `str_repeat('x', …)`, where a byte offset
 * and a character offset are the same number, so the cut could never split
 * anything and the bug was invisible.
 *
 * What matters here is the boundary, not the happy path: cut at exactly the
 * offsets where a 2-, 3- or 4-byte character straddles the budget.
 */
final class Utf8Test extends TestCase
{
    /**
     * One character of each UTF-8 width, with the byte count each occupies.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function characterWidths(): iterable
    {
        yield '2-byte' => ['é', 2];
        yield '3-byte' => ['…', 3];
        yield '3-byte CJK' => ['漢', 3];
        yield '4-byte emoji' => ['😀', 4];
    }

    #[Test]
    #[DataProvider('characterWidths')]
    public function cutNeverSplitsACharacterAtAnyBoundary(string $char, int $declaredWidth): void
    {
        self::assertSame($declaredWidth, \strlen($char), 'the provider width is wrong');

        $width = $declaredWidth;

        // The character starts at every offset from 0 to $width-1 relative to
        // the budget, so between 1 and $width bytes of it fall inside the cut.
        for ($offset = 1; $offset <= $width; ++$offset) {
            $text = str_repeat('a', 10).$char.str_repeat('b', 10);
            $budget = 10 + $offset;

            $cut = Utf8::cut($text, $budget);

            self::assertTrue(
                Utf8::isValid($cut),
                sprintf('cut at budget %d split a %d-byte character', $budget, $width),
            );
            self::assertLessThanOrEqual(
                $budget,
                \strlen($cut),
                'the cut must never exceed its byte budget',
            );
            self::assertStringStartsWith(str_repeat('a', 10), $cut);
        }
    }

    #[Test]
    #[DataProvider('characterWidths')]
    public function cutBacksUpToTheCharacterStartRatherThanEmittingHalfOfIt(string $char, int $declaredWidth): void
    {
        self::assertSame($declaredWidth, \strlen($char), 'the provider width is wrong');

        $text = str_repeat('a', 10).$char.str_repeat('b', 10);

        // Budget lands one byte into the character: it cannot be completed, so
        // it must be dropped whole rather than half-written.
        $cut = Utf8::cut($text, 11);

        self::assertSame(str_repeat('a', 10), $cut);
        self::assertTrue(Utf8::isValid($cut));
    }

    #[Test]
    public function cutReturnsShortTextUntouched(): void
    {
        self::assertSame('héllo', Utf8::cut('héllo', 100));
        self::assertSame('héllo', Utf8::cut('héllo', 6));
    }

    #[Test]
    public function cutWithAZeroOrNegativeBudgetIsEmpty(): void
    {
        self::assertSame('', Utf8::cut('anything', 0));
        self::assertSame('', Utf8::cut('anything', -5));
    }

    #[Test]
    public function cutOfAStringThatIsEmptyStaysEmpty(): void
    {
        self::assertSame('', Utf8::cut('', 10));
    }

    /**
     * The exact production shape: a JSON tool result full of multi-byte
     * punctuation, capped at the window default, must still encode.
     */
    #[Test]
    public function aMultiByteToolResultSurvivesJsonEncodingAfterCapping(): void
    {
        $result = str_repeat('{"id":"a","name":"café — naïve … 漢字 😀"},', 900);

        foreach ([17203, 17204, 17205, 17206, 17207] as $budget) {
            $capped = Utf8::cut($result, $budget);

            self::assertTrue(Utf8::isValid($capped), sprintf('cut at %d is not valid UTF-8', $budget));
            self::assertNotFalse(
                json_encode(['tool' => 'search', 'content' => $capped]),
                sprintf('json_encode refused the cut at %d', $budget),
            );
        }
    }

    /**
     * cut() fixes a split character; it does not repair malformed input.
     *
     * This is the guard against an unbounded walk-back: if the damage is
     * earlier in the string, trimming the end can never make it validate, so
     * "walk until valid" would empty a 17 KB tool result a byte at a time.
     */
    #[Test]
    public function cutDoesNotStripAnAlreadyMalformedStringToNothing(): void
    {
        $text = 'good text '."\xC3".' more good text '.str_repeat('z', 100);

        $cut = Utf8::cut($text, 50);

        // The three-byte walk-back is all it may spend, and the bulk survives.
        self::assertGreaterThanOrEqual(46, \strlen($cut));
        self::assertStringContainsString('good text', $cut);
    }

    #[Test]
    public function cutOfAlreadyMalformedTextTerminatesQuickly(): void
    {
        // A large buffer with a broken byte at the very front: the pathological
        // shape for an unbounded walk-back.
        $text = "\x80".str_repeat('a', 20000);

        $started = microtime(true);
        $cut = Utf8::cut($text, 17000);
        $elapsed = microtime(true) - $started;

        self::assertGreaterThan(16000, \strlen($cut));
        self::assertLessThan(0.5, $elapsed, 'cut() is not bounded — the walk-back is quadratic');
    }

    #[Test]
    public function tailDropsTheSplitCharacterAtTheFront(): void
    {
        // 13 bytes: 'aaaaa' (0-4), 漢 (5-7), 'bbbbb' (8-12).
        $text = str_repeat('a', 5).'漢'.str_repeat('b', 5);

        // 7 bytes from the end starts at index 6 — inside 漢. The split
        // character is dropped whole, leaving the five b's.
        $tail = Utf8::tail($text, 7);

        self::assertTrue(Utf8::isValid($tail));
        self::assertSame('bbbbb', $tail);
    }

    #[Test]
    public function tailReturnsShortTextUntouched(): void
    {
        self::assertSame('héllo', Utf8::tail('héllo', 100));
        self::assertSame('héllo', Utf8::tail('héllo', 6));
    }

    #[Test]
    public function tailIsBoundedAndKeepsTheText(): void
    {
        $text = str_repeat('a', 20000)."\x80";

        $started = microtime(true);
        $tail = Utf8::tail($text, 16000);
        $elapsed = microtime(true) - $started;

        // The trailing broken byte is dropped as the split character; the
        // rest is kept rather than stripped.
        self::assertGreaterThan(15990, \strlen($tail));
        self::assertLessThanOrEqual(16000, \strlen($tail));
        self::assertLessThan(0.5, $elapsed);
    }

    #[Test]
    public function scrubReplacesMalformedBytesAndLeavesValidTextAlone(): void
    {
        self::assertSame('plain', Utf8::scrub('plain'));

        // A lone continuation byte, and a truncated 3-byte sequence.
        $broken = "bad \x80 byte";
        self::assertTrue(Utf8::isValid(Utf8::scrub($broken)));
        self::assertNotSame($broken, Utf8::scrub($broken));

        $truncated = "cut \xE2\x80";
        self::assertTrue(Utf8::isValid(Utf8::scrub($truncated)));
    }

    #[Test]
    public function scrubKeepsTheLegibleTextAroundTheDamage(): void
    {
        $scrubbed = Utf8::scrub("before \xC3 after");

        self::assertStringContainsString('before', $scrubbed);
        self::assertStringContainsString('after', $scrubbed);
        self::assertTrue(Utf8::isValid($scrubbed));
    }
}
