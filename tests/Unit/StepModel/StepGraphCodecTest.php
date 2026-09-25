<?php

declare(strict_types=1);

namespace App\Tests\Unit\StepModel;

use App\Entity\Step;
use App\Entity\ToolboxMode;
use App\StepModel\StepFormatException;
use App\StepModel\StepGraphCodec;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §13.2: arrays in, edges stored, arrays rendered back out. The
 * codec is the only translation between the authoring/wire format and
 * the persisted depends_on edges.
 */
#[AllowMockObjectsWithoutExpectations]
final class StepGraphCodecTest extends TestCase
{
    private function codec(): StepGraphCodec
    {
        return new StepGraphCodec();
    }

    /**
     * @param list<int>    $dependsOn
     * @param list<string> $toolbox
     */
    private function step(int $id, string $title, array $dependsOn = [], ToolboxMode $mode = ToolboxMode::Explicit, array $toolbox = [], ?string $brief = null): Step
    {
        $step = $this->createMock(Step::class);
        $step->method('getId')->willReturn($id);
        $step->method('getTitle')->willReturn($title);
        $step->method('getBrief')->willReturn($brief ?? "Do {$title}.");
        $step->method('getDependsOn')->willReturn($dependsOn);
        $step->method('getToolboxMode')->willReturn($mode);
        $step->method('getToolbox')->willReturn($toolbox);

        return $step;
    }

    // ---------------------------------------------------------------- parse

    public function testParseNullAndEmptyYieldNoSpecs(): void
    {
        self::assertSame([], $this->codec()->parse(null));
        self::assertSame([], $this->codec()->parse([]));
    }

    public function testParseSingleLevel(): void
    {
        $specs = $this->codec()->parse([
            [
                ['title' => 'Weather', 'brief' => 'Fetch weather.'],
                ['title' => 'Calendar', 'brief' => 'Fetch calendar.'],
            ],
        ]);

        self::assertCount(2, $specs);
        self::assertSame('Weather', $specs[0]->title);
        self::assertSame('Fetch weather.', $specs[0]->brief);
        self::assertSame(0, $specs[0]->level);
        self::assertSame(ToolboxMode::Explicit, $specs[0]->toolboxMode, 'explicit is the default mode');
        self::assertSame([], $specs[0]->toolbox);
        self::assertSame(0, $specs[1]->level);
    }

    public function testParseLevelsAssignIndexes(): void
    {
        $specs = $this->codec()->parse([
            [['title' => 'A', 'brief' => 'a']],
            [['title' => 'B', 'brief' => 'b']],
            [['title' => 'C', 'brief' => 'c']],
        ]);

        self::assertSame([0, 1, 2], array_map(static fn ($s) => $s->level, $specs));
    }

    public function testParseCarriesToolbox(): void
    {
        $specs = $this->codec()->parse([
            [['title' => 'Weather', 'brief' => 'Fetch.', 'toolbox_mode' => 'tags', 'toolbox' => ['weather', 'core']]],
        ]);

        self::assertSame(ToolboxMode::Tags, $specs[0]->toolboxMode);
        self::assertSame(['weather', 'core'], $specs[0]->toolbox);
    }

    public function testParseTrimsWhitespace(): void
    {
        $specs = $this->codec()->parse([
            [['title' => '  Weather  ', 'brief' => "  Fetch.\n", 'toolbox' => [' get_weather ']]],
        ]);

        self::assertSame('Weather', $specs[0]->title);
        self::assertSame('Fetch.', $specs[0]->brief);
        self::assertSame(['get_weather'], $specs[0]->toolbox);
    }

    public function testParseAcceptsStdClassSteps(): void
    {
        // json_decode($json, false) callers produce stdClass objects.
        $spec = (object) ['title' => 'Weather', 'brief' => 'Fetch.'];

        $specs = $this->codec()->parse([[$spec]]);

        self::assertSame('Weather', $specs[0]->title);
    }

    public function testParseRejectsNonListRoot(): void
    {
        try {
            $this->codec()->parse(['title' => 'Weather']);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertStringContainsString('steps must be an array of levels', $e->getMessage());
        }
    }

    public function testParseRejectsEmptyLevel(): void
    {
        try {
            $this->codec()->parse([[], [['title' => 'A', 'brief' => 'a']]]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertSame(['steps[0] is empty — every level needs at least one step.'], $e->problems);
        }
    }

    public function testParseRejectsMissingFieldsWithIndexedPaths(): void
    {
        try {
            $this->codec()->parse([
                [['brief' => 'No title.']],
                [['title' => 'Has title', 'brief' => '']],
            ]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertCount(2, $e->problems);
            self::assertStringContainsString('steps[0][0].title must be a non-empty string', $e->problems[0]);
            self::assertStringContainsString('steps[1][0].brief must be a non-empty string', $e->problems[1]);
        }
    }

    public function testParseRejectsUnknownFields(): void
    {
        try {
            $this->codec()->parse([
                [['title' => 'A', 'brief' => 'a', 'tooLbox' => ['x']]],
            ]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertStringContainsString('unknown field "tooLbox"', $e->problems[0]);
        }
    }

    public function testParseRejectsBadToolboxMode(): void
    {
        try {
            $this->codec()->parse([
                [['title' => 'A', 'brief' => 'a', 'toolbox_mode' => 'everything']],
            ]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertStringContainsString('toolbox_mode must be "tags" or "explicit"', $e->problems[0]);
        }
    }

    public function testParseReportsAllProblemsInOnePass(): void
    {
        try {
            $this->codec()->parse([
                [['title' => '', 'brief' => '', 'toolbox' => 'not-a-list']],
                [['title' => 'OK', 'brief' => 'ok', 'toolbox' => ['x', 42]]],
            ]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertCount(4, $e->problems);
            $report = implode("\n", $e->problems);
            self::assertStringContainsString('steps[0][0].title', $report);
            self::assertStringContainsString('steps[0][0].brief', $report);
            self::assertStringContainsString('steps[0][0].toolbox must be an array', $report);
            self::assertStringContainsString('steps[1][0].toolbox[1]', $report);
        }
    }

    public function testParseRejectsOverlongTitle(): void
    {
        try {
            $this->codec()->parse([
                [['title' => str_repeat('x', 201), 'brief' => 'a']],
            ]);
            self::fail('Expected StepFormatException.');
        } catch (StepFormatException $e) {
            self::assertStringContainsString('longer than 200 characters', $e->problems[0]);
        }
    }

    // --------------------------------------------------------------- render

    public function testRenderEmptyYieldsEmpty(): void
    {
        self::assertSame([], $this->codec()->render([]));
    }

    public function testRenderDerivesLevelsFromEdges(): void
    {
        $weather = $this->step(1, 'Weather');
        $calendar = $this->step(2, 'Calendar');
        $summary = $this->step(3, 'Summary', [1, 2]);

        $rendered = $this->codec()->render([$weather, $calendar, $summary]);

        self::assertCount(2, $rendered);
        self::assertCount(2, $rendered[0]);
        self::assertCount(1, $rendered[1]);
        self::assertSame('Weather', $rendered[0][0]['title']);
        self::assertSame('Calendar', $rendered[0][1]['title']);
        self::assertSame('Summary', $rendered[1][0]['title']);
    }

    public function testRenderRoundTripsParse(): void
    {
        $input = [
            [
                ['title' => 'Weather', 'brief' => 'Fetch weather.', 'toolbox_mode' => 'explicit', 'toolbox' => ['get_weather']],
                ['title' => 'Calendar', 'brief' => 'Fetch calendar.', 'toolbox_mode' => 'explicit', 'toolbox' => []],
            ],
            [
                ['title' => 'Summary', 'brief' => 'Summarize all.', 'toolbox_mode' => 'explicit', 'toolbox' => []],
            ],
        ];

        // parse → (as if persisted) → render must reproduce the input.
        $specs = $this->codec()->parse($input);
        $persisted = [];
        $id = 0;
        $idsByLevel = [];
        foreach ($specs as $spec) {
            ++$id;
            $persisted[] = $this->step($id, $spec->title, $idsByLevel[$spec->level - 1] ?? [], $spec->toolboxMode, $spec->toolbox, $spec->brief);
            $idsByLevel[$spec->level][] = $id;
        }

        $rendered = $this->codec()->render($persisted);

        $normalized = array_map(
            static fn (array $level): array => array_map(
                static fn (array $step): array => [
                    'title' => $step['title'],
                    'brief' => $step['brief'],
                    'toolbox_mode' => $step['toolbox_mode'],
                    'toolbox' => $step['toolbox'],
                ],
                $level,
            ),
            $input,
        );
        self::assertSame($normalized, $rendered);
    }

    public function testRenderCompressesSkippedLevels(): void
    {
        // Hand-built graph (not expressible in the wire format): A → C
        // skips B's level entirely.
        $a = $this->step(1, 'A');
        $b = $this->step(2, 'B');
        $c = $this->step(3, 'C', [1]);

        $rendered = $this->codec()->render([$a, $b, $c]);

        // Display stays total: C lands at level 1 (one past its deepest dep).
        self::assertCount(2, $rendered);
        self::assertSame(['A', 'B'], array_column($rendered[0], 'title'));
        self::assertSame(['C'], array_column($rendered[1], 'title'));
    }

    public function testRenderToleratesCycleAndDanglingEdges(): void
    {
        // Invalid graph (draft pending fixes): display must not throw.
        $a = $this->step(1, 'A', [2, 999]);
        $b = $this->step(2, 'B', [1]);

        $rendered = $this->codec()->render([$a, $b]);

        self::assertCount(1, $rendered, 'both cycle members land one past the deepest resolved level');
        self::assertCount(2, $rendered[0]);
    }

    // --------------------------------------------------------------- levels

    public function testLevelsForLinearChain(): void
    {
        $levels = $this->codec()->levels([
            $this->step(1, 'A'),
            $this->step(2, 'B', [1]),
            $this->step(3, 'C', [2]),
        ]);

        self::assertSame([1 => 0, 2 => 1, 3 => 2], $levels);
    }

    public function testLevelsTakeLongestPath(): void
    {
        // A → B → C and A → C: C must be level 2, not 1.
        $levels = $this->codec()->levels([
            $this->step(1, 'A'),
            $this->step(2, 'B', [1]),
            $this->step(3, 'C', [2, 1]),
        ]);

        self::assertSame([1 => 0, 2 => 1, 3 => 2], $levels);
    }

    public function testLevelsIgnoreDuplicatesSelfAndDangling(): void
    {
        $levels = $this->codec()->levels([
            $this->step(1, 'A', [1, 1, 999]),
        ]);

        self::assertSame([1 => 0], $levels);
    }
}
