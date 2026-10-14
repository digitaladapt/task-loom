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
 *
 * The long-period cases are the regression the detector shipped without.
 * A session model read its own context back and recited a ~1.7 KB exchange
 * verbatim, over and over, for two hours: the repeated block was larger than
 * the block rule's period ceiling, so the block rule could not see it, and
 * the line rule could not either because the recited lines are not
 * byte-identical across cycles and its window is six lines while the
 * carry-over that makes each cycle unique sits ~27 lines back.
 * `testCatchesTheRecitationThatPromptedTheRecurrenceRule` uses a fixture
 * taken from that run.
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

    // ---------------------------------------------------------------- long period

    /**
     * A block larger than the period ceiling, recited verbatim, must be
     * caught — the shape the block rule's ceiling makes invisible.
     *
     * The unit here is 200 chars, comfortably past `maxPeriodChars` (128), so
     * the block rule never tries it and the line rule cannot see it (the unit
     * is not one line and its lines are not all identical).
     */
    public function testCatchesABlockLongerThanThePeriodCeiling(): void
    {
        $detector = new LoopDetector();

        $unit = '- [you] The observatory is a National Historic Landmark. Wysor built it with his own hands, '
            .'gave it all away, and let the sky do the talking. Friday night is the drive, and the '
            .'Blue Ridge is peaking, which is the whole reason this thread was worth following at all.'."\n";

        self::assertGreaterThan(128, \strlen($unit), 'the fixture must be past the block ceiling to be a regression');

        $hit = null;
        for ($i = 0; $i < 10 && null === $hit; ++$i) {
            $hit = $detector->observe($unit);
        }

        self::assertNotNull($hit, 'a verbatim block longer than the period ceiling is a loop, not a refrain');
        self::assertSame('recurrence', $hit->kind);
        self::assertGreaterThan(128, $hit->periodChars);
        self::assertGreaterThanOrEqual(3, $hit->repeats);
    }

    /**
     * The regression itself: the recitation from the run that prompted the
     * rule, run through the detector as a token stream would deliver it.
     *
     * The fixture is 16 KB of real model output in which a ~1.7 KB
     * `[you]`/`[operator]` exchange repeats; every line inside a cycle is
     * unique, so the line rule is blind to it by construction.
     */
    public function testCatchesTheRecitationThatPromptedTheRecurrenceRule(): void
    {
        $fixture = __DIR__.'/../../Fixtures/llm/looped_recitation.txt';
        $text = file_get_contents($fixture);
        self::assertIsString($text);
        self::assertGreaterThan(8000, \strlen($text), 'the fixture should be the real capture, not a summary');

        $detector = new LoopDetector();

        $hit = null;
        $fed = 0;
        foreach (str_split($text, 17) as $chunk) {
            $fed += \strlen($chunk);
            $hit = $detector->observe($chunk);
            if (null !== $hit) {
                break;
            }
        }

        self::assertNotNull($hit, 'the recitation must be caught');
        self::assertSame('recurrence', $hit->kind, 'the block rule cannot see a 1.7 KB period; the long-range rule must');
        self::assertGreaterThan(1000, $hit->periodChars);
        self::assertGreaterThanOrEqual(3, $hit->repeats);
        self::assertLessThan(\strlen($text), $fed, 'it must fire before the stream ends, not after');
    }

    /**
     * The long-period rule requires the repetition to be *verbatim*. Two
     * passes of a quoted passage, a code block shown again at the end, and a
     * long line used twice are all left alone.
     */
    public function testLongRepetitionNeedsThreeVerbatimPasses(): void
    {
        $passage = 'The river is the same river while none of its water is the same water, '
            .'and nobody thinks the river is lying about that. Nor should they: the shape '
            .'is the thing, and the shape is what we are arguing about when we argue.';

        $detector = new LoopDetector();
        $text = "Here is the passage:\n\n".$passage."\n\nAnd later, quoting it again:\n\n".$passage."\n\nDone.\n";

        $hit = null;
        foreach (str_split($text, 13) as $chunk) {
            $hit ??= $detector->observe($chunk);
        }

        self::assertNull($hit, 'a passage used twice is a quotation, not a loop');
    }

    public function testARepeatedBlockSeparatedByNewContentDoesNotFire(): void
    {
        $unit = str_repeat('The observatory sits above the valley and the road up to it is gravel. ', 4);

        $detector = new LoopDetector();
        $text = $unit."\n\n".str_repeat('Something else entirely, with its own rhythm and its own words. ', 4)
            ."\n\n".$unit."\n\n";

        $hit = null;
        foreach (str_split($text, 32) as $chunk) {
            $hit ??= $detector->observe($chunk);
        }

        self::assertNull($hit, 'a block used twice around fresh content is structure, not a stall');
    }

    /**
     * A loop that varies only in line ending or spacing is still a loop: a
     * proxy rewriting `\n` as `\r\n`, or a model emitting trailing spaces,
     * must not make the line rule blind.
     */
    public function testLineRepetitionSurvivesLineEndingAndSpacingDrift(): void
    {
        $detector = new LoopDetector();

        $hit = null;
        for ($i = 0; $i < 8 && null === $hit; ++$i) {
            $ending = 0 === $i % 2 ? "\n" : "\r\n";
            $hit = $detector->observe('Declare when you are ready.'.$ending);
        }

        self::assertNotNull($hit, 'the same sentence with mixed line endings is the same line');
        self::assertSame('line', $hit->kind);
    }

    public function testTheBufferIsBoundedSoLongGenerationsCostTheSame(): void
    {
        $detector = new LoopDetector();

        for ($i = 0; $i < 3000; ++$i) {
            $detector->observe('x');
        }

        self::assertLessThanOrEqual(
            LoopDetector::BUFFER_LIMIT,
            $detector->bufferLength(),
            'a long generation must not grow the watched buffer unboundedly',
        );
    }
}
