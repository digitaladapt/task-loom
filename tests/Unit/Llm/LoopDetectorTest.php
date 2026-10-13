<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\LoopDetector;
use PHPUnit\Framework\TestCase;

/**
 * The loop detector's two-sided contract: catch the degenerate repetition
 * that makes a model "spin forever", and leave ordinary text alone.
 *
 * The false-positive side is the one that matters most — a detector that
 * cuts real output is worse than no detector — so the negatives here are
 * drawn from text that genuinely looks repetitive (doubled words, markdown
 * rules, code blocks, tables, repeated punctuation) and must all pass.
 */
final class LoopDetectorTest extends TestCase
{
    public function testCatchesAShortBlockRepeatedManyTimes(): void
    {
        $detector = new LoopDetector(minSpanChars: 160, minRepeats: 6);

        // "I will keep thinking about this. " is 33 chars; 20 repetitions
        // is 660 chars — far past 6 repeats and 160 span.
        $hit = null;
        for ($i = 0; $i < 20 && null === $hit; ++$i) {
            $hit = $detector->observe('I will keep thinking about this. ');
        }

        self::assertNotNull($hit);
        self::assertSame('block', $hit->kind);
        self::assertSame(33, $hit->periodChars);
        self::assertGreaterThanOrEqual(6, $hit->repeats);
        self::assertSame('I will keep thinking about this. ', $hit->preview);
    }

    public function testCatchesASingleCharacterRunOnlyPastTheSpanFloor(): void
    {
        $detector = new LoopDetector(minSpanChars: 160, minRepeats: 6);

        $hit = null;
        $length = 0;
        while (null === $hit && $length < 400) {
            $hit = $detector->observe('a');
            ++$length;
        }

        self::assertNotNull($hit);
        self::assertSame(1, $hit->periodChars);
        self::assertGreaterThanOrEqual(160, $hit->repeats, 'one character needs the full span floor of evidence');
    }

    public function testCatchesWholeLineRepetition(): void
    {
        $detector = new LoopDetector(minSpanChars: 40, minRepeats: 4);

        // Lines longer than the block ceiling: only the line rule can see
        // this one. Each line is 150 chars.
        $line = str_repeat('the quick brown fox jumps over the lazy dog and keeps going ', 3)."\n";

        $hit = null;
        for ($i = 0; $i < 6 && null === $hit; ++$i) {
            $hit = $detector->observe($line);
        }

        self::assertNotNull($hit);
        self::assertSame('line', $hit->kind);
        self::assertGreaterThanOrEqual(4, $hit->repeats);
    }

    public function testOrdinaryProseNeverFires(): void
    {
        $detector = new LoopDetector();

        $prose = 'The briefing for today: three meetings, the first at 09:00 with the '
            .'design team about the fence repair. After that, a review of the quarterly '
            .'numbers — nothing that needs preparation, though the finance lead asked for '
            .'the figures in advance. It will rain in the afternoon, so the garden work '
            .'should move to the morning if there is time. Nothing here repeats itself '
            .'in any mechanical way, which is precisely the point of this test: normal '
            .'text contains repeated words and letters and must survive the detector.';

        $hit = null;
        foreach (str_split($prose, 7) as $chunk) {
            $hit ??= $detector->observe($chunk);
        }

        self::assertNull($hit);
    }

    public function testDoubledWordsAndQuotedRefrainsSurvive(): void
    {
        $detector = new LoopDetector();

        $text = 'He said "no, no, no" and then "no, no, no" again — very very very '
            .'emphatically. As the song goes: "never gonna give you up, never gonna '  // one quoted refrain
            .'let you down". And the table below repeats its header and separator:'
            ."\n\n| name | value |\n|---|---|\n| a | 1 |\n| b | 2 |\n| c | 3 |\n";

        $hit = null;
        foreach (str_split($text, 11) as $chunk) {
            $hit ??= $detector->observe($chunk);
        }

        self::assertNull($hit);
    }

    public function testMarkdownRuleAndRepeatedPunctuationSurvive(): void
    {
        $detector = new LoopDetector();

        $text = "Some words.\n\n---\n\nMore words follow the rule.\n\n* * *\n\n"
            .'Punctuation: !!!!! ..... ????? ------ ////// And a long dash sequence: '
            .str_repeat('-', 60)."\n\nEnd.\n";

        $hit = null;
        foreach (str_split($text, 5) as $chunk) {
            $hit ??= $detector->observe($chunk);
        }

        self::assertNull($hit);
    }

    public function testSparseRepetitionAcrossLongTextDoesNotFire(): void
    {
        $detector = new LoopDetector();

        // The same sentence three times, separated by other content: a
        // refrain, not a loop.
        $filler = 'Nothing to see here, just ordinary filler that varies enough to matter. ';
        $text = $filler.'The cat sat on the mat. '
            .$filler.'Something else entirely, a different clause with its own rhythm. '
            .$filler.'The cat sat on the mat. ';

        $hit = null;
        foreach (str_split($text, 9) as $chunk) {
            $hit ??= $detector->observe($chunk);
        }

        self::assertNull($hit);
    }

    public function testTheHitCarriesItsEvidence(): void
    {
        $detector = new LoopDetector(minSpanChars: 60, minRepeats: 5);

        $hit = null;
        for ($i = 0; $i < 30 && null === $hit; ++$i) {
            $hit = $detector->observe('tick tock ');
        }

        self::assertNotNull($hit);
        self::assertSame(10, $hit->periodChars);
        self::assertStringContainsString('tick tock', $hit->describe());
        self::assertStringContainsString('block', $hit->describe());
        self::assertStringContainsString('chars', $hit->describe());
    }

    public function testTheBufferIsBoundedSoLongGenerationsCostTheSame(): void
    {
        $detector = new LoopDetector();

        for ($i = 0; $i < 3000; ++$i) {
            $detector->observe('x');
        }

        self::assertLessThanOrEqual(8192, $detector->bufferLength(), 'a long generation must not grow the watched buffer unboundedly');
    }
}
