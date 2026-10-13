<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\LlmGuards;
use PHPUnit\Framework\TestCase;

/**
 * The guard windows' arithmetic and validation.
 *
 * A misconfigured guard must fail where it is read, naming its knob — the
 * project's rule for deployment knobs, and doubly important here, because a
 * guard that silently never fires is indistinguishable from a guard that
 * works until the day the model wedges.
 */
final class LlmGuardsTest extends TestCase
{
    public function testDefaultsAreTheDocumentedNumbers(): void
    {
        $guards = new LlmGuards();

        self::assertSame(90.0, $guards->firstTokenSeconds);
        self::assertSame(120.0, $guards->silenceSeconds);
        self::assertSame(300.0, $guards->softDeadlineSeconds);
        self::assertSame(150.0, $guards->softExtensionSeconds);
        self::assertSame(2, $guards->maxSoftExtensions);
        self::assertSame(600.0, $guards->maxDurationSeconds);
        self::assertSame(160, $guards->loopMinSpanChars);
        self::assertSame(6, $guards->loopMinRepeats);
    }

    public function testTheLegacyShapeKeepsTheOldWorstCaseExactly(): void
    {
        $guards = LlmGuards::legacy(300);

        // A deployment that never sets a new knob gets exactly one bound of
        // TASKLOOM_LLM_TIMEOUT: no extensions, no extra time.
        self::assertSame(300.0, $guards->softDeadlineSeconds);
        self::assertSame(300.0, $guards->maxDurationSeconds);
        self::assertSame(0, $guards->maxSoftExtensions);
    }

    public function testTheLegacyShapeClampsTheWindowsItInherits(): void
    {
        $guards = LlmGuards::legacy(30);

        // Windows bigger than the whole bound would never be reachable
        // inside it.
        self::assertSame(30.0, $guards->firstTokenSeconds);
        self::assertSame(30.0, $guards->silenceSeconds);
    }

    public function testFromEnvAppliesDefaultsForUnsetValues(): void
    {
        $guards = LlmGuards::fromEnv(300, null, '', '  ', null, null, null, null, null);

        self::assertSame(90.0, $guards->firstTokenSeconds);
        self::assertSame(120.0, $guards->silenceSeconds);
        self::assertSame(300.0, $guards->softDeadlineSeconds);
        self::assertSame(150.0, $guards->softExtensionSeconds);
        self::assertSame(2, $guards->maxSoftExtensions);
        // The smallest hard cap consistent with the extension arithmetic.
        self::assertSame(600.0, $guards->maxDurationSeconds);
    }

    public function testFromEnvLetsTheSoftDeadlineInheritTheLegacyTimeout(): void
    {
        $guards = LlmGuards::fromEnv(450, '60', null, null, null, null, null, null, null);

        self::assertSame(450.0, $guards->softDeadlineSeconds, 'unset soft deadline inherits TASKLOOM_LLM_TIMEOUT');
        self::assertSame(60.0, $guards->firstTokenSeconds);
    }

    public function testFromEnvReadsEveryKnob(): void
    {
        $guards = LlmGuards::fromEnv(300, '45', '75', '240', '60', '3', '500', '200', '8');

        self::assertSame(45.0, $guards->firstTokenSeconds);
        self::assertSame(75.0, $guards->silenceSeconds);
        self::assertSame(240.0, $guards->softDeadlineSeconds);
        self::assertSame(60.0, $guards->softExtensionSeconds);
        self::assertSame(3, $guards->maxSoftExtensions);
        self::assertSame(500.0, $guards->maxDurationSeconds);
        self::assertSame(200, $guards->loopMinSpanChars);
        self::assertSame(8, $guards->loopMinRepeats);
    }

    public function testANonNumericKnobFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('number');

        LlmGuards::fromEnv(300, 'ninety');
    }

    public function testAHardCapBelowTheSoftDeadlineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TASKLOOM_LLM_MAX_DURATION');

        new LlmGuards(softDeadlineSeconds: 300, maxDurationSeconds: 200);
    }

    public function testExtensionsThatCouldNeverFitUnderTheHardCapAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('extensions could never all be granted');

        // 300 + 2×150 = 600 > 500.
        new LlmGuards(softDeadlineSeconds: 300, maxSoftExtensions: 2, softExtensionSeconds: 150, maxDurationSeconds: 500);
    }

    public function testEachWindowRefusesZeroOrNegative(): void
    {
        foreach ([
            static fn (): LlmGuards => new LlmGuards(firstTokenSeconds: 0),
            static fn (): LlmGuards => new LlmGuards(silenceSeconds: -1),
            static fn (): LlmGuards => new LlmGuards(softDeadlineSeconds: 0),
            static fn (): LlmGuards => new LlmGuards(softExtensionSeconds: -5),
        ] as $i => $factory) {
            try {
                $factory();
                self::fail("case {$i} should have thrown");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('TASKLOOM_LLM_', $e->getMessage(), 'the message must name the knob');
            }
        }
    }

    public function testNegativeExtensionsAndTinyLoopWindowsAreRefused(): void
    {
        foreach ([
            static fn (): LlmGuards => new LlmGuards(maxSoftExtensions: -1),
            static fn (): LlmGuards => new LlmGuards(loopMinSpanChars: 8),
            static fn (): LlmGuards => new LlmGuards(loopMinRepeats: 2),
        ] as $i => $factory) {
            try {
                $factory();
                self::fail("case {$i} should have thrown");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('TASKLOOM_LLM_', $e->getMessage());
            }
        }
    }
}
