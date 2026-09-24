<?php

declare(strict_types=1);

namespace App\Tests\Unit\StepModel;

use App\Entity\Step;
use App\StepModel\StepGraphException;
use App\StepModel\StepGraphValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §13.2 DAG validation: no cycles, no self-dependencies, and every
 * dependency edge referencing a step of the same task. The validator is
 * id-based — depends_on stores step ids — so the fixture steps carry
 * stubbed ids, the way a persisted graph would.
 */
#[AllowMockObjectsWithoutExpectations]
final class StepGraphValidatorTest extends TestCase
{
    /**
     * @param list<int> $dependsOn
     */
    private function step(int $id, string $title, array $dependsOn = []): Step
    {
        $step = $this->createMock(Step::class);
        $step->method('getId')->willReturn($id);
        $step->method('getTitle')->willReturn($title);
        $step->method('getDependsOn')->willReturn($dependsOn);

        return $step;
    }

    private function validator(): StepGraphValidator
    {
        return new StepGraphValidator();
    }

    public function testEmptyGraphIsValid(): void
    {
        // Zero steps: a task without steps runs exactly as v1 (SPEC §13.1).
        self::assertSame([], $this->validator()->problems([]));
        $this->validator()->assertValid([]);
        $this->addToAssertionCount(1);
    }

    public function testSingleStepIsValid(): void
    {
        self::assertSame([], $this->validator()->problems([$this->step(1, 'Weather')]));
    }

    public function testLinearChainIsValid(): void
    {
        $steps = [
            $this->step(1, 'Weather'),
            $this->step(2, 'Calendar', [1]),
            $this->step(3, 'Summary', [2]),
        ];

        self::assertSame([], $this->validator()->problems($steps));
    }

    public function testDiamondIsValid(): void
    {
        $steps = [
            $this->step(1, 'Root'),
            $this->step(2, 'Left', [1]),
            $this->step(3, 'Right', [1]),
            $this->step(4, 'Join', [2, 3]),
        ];

        self::assertSame([], $this->validator()->problems($steps));
    }

    public function testParallelSiblingsAreValid(): void
    {
        // The Morning Briefing shape: weather | calendar | transactions.
        $steps = [
            $this->step(1, 'Weather'),
            $this->step(2, 'Calendar'),
            $this->step(3, 'Transactions'),
        ];

        self::assertSame([], $this->validator()->problems($steps));
    }

    public function testDuplicateDependenciesAreHarmless(): void
    {
        $steps = [
            $this->step(1, 'Weather'),
            $this->step(2, 'Summary', [1, 1]),
        ];

        self::assertSame([], $this->validator()->problems($steps));
    }

    public function testSelfDependencyIsReported(): void
    {
        $problems = $this->validator()->problems([$this->step(1, 'Weather', [1])]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('"Weather" depends on itself', $problems[0]);
    }

    public function testDanglingDependencyIsReported(): void
    {
        $steps = [
            $this->step(1, 'Weather'),
            $this->step(2, 'Summary', [99]),
        ];

        $problems = $this->validator()->problems($steps);

        self::assertCount(1, $problems);
        self::assertStringContainsString('"Summary" depends on step 99, which is not a step of this task', $problems[0]);
    }

    public function testTwoStepCycleIsReported(): void
    {
        $steps = [
            $this->step(1, 'Weather', [2]),
            $this->step(2, 'Calendar', [1]),
        ];

        $problems = $this->validator()->problems($steps);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Dependency cycle', $problems[0]);
        self::assertStringContainsString('"Weather"', $problems[0]);
        self::assertStringContainsString('"Calendar"', $problems[0]);
    }

    public function testThreeStepCycleIsReportedAsPath(): void
    {
        $steps = [
            $this->step(1, 'A', [2]),
            $this->step(2, 'B', [3]),
            $this->step(3, 'C', [1]),
        ];

        $problems = $this->validator()->problems($steps);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Dependency cycle', $problems[0]);
        self::assertMatchesRegularExpression('/"A" → "B" → "C" → "A"|"B" → "C" → "A" → "B"|"C" → "A" → "B" → "C"/', $problems[0]);
    }

    public function testCycleMessageExcludesDownstreamSteps(): void
    {
        // C depends on the A↔B cycle but is not itself on it: it is
        // unpeelable (downstream of a cycle) yet must not appear in the
        // cycle path the human is shown.
        $steps = [
            $this->step(1, 'A', [2]),
            $this->step(2, 'B', [1]),
            $this->step(3, 'C', [1]),
        ];

        $problems = $this->validator()->problems($steps);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Dependency cycle', $problems[0]);
        self::assertStringNotContainsString('"C"', $problems[0]);
    }

    public function testAllProblemsAreReportedTogether(): void
    {
        $steps = [
            $this->step(1, 'Selfish', [1]),
            $this->step(2, 'Dangling', [99]),
            $this->step(3, 'Loop1', [4]),
            $this->step(4, 'Loop2', [3]),
        ];

        $problems = $this->validator()->problems($steps);

        self::assertCount(3, $problems);
        $report = implode("\n", $problems);
        self::assertStringContainsString('depends on itself', $report);
        self::assertStringContainsString('not a step of this task', $report);
        self::assertStringContainsString('Dependency cycle', $report);
    }

    public function testAssertValidThrowsWithAllProblems(): void
    {
        $steps = [
            $this->step(1, 'Selfish', [1]),
            $this->step(2, 'Dangling', [99]),
        ];

        try {
            $this->validator()->assertValid($steps);
            self::fail('Expected a StepGraphException.');
        } catch (StepGraphException $e) {
            self::assertCount(2, $e->problems);
            self::assertStringContainsString('depends on itself', $e->getMessage());
            self::assertStringContainsString('not a step of this task', $e->getMessage());
        }
    }

    public function testLongChainValidatesWithoutRecursion(): void
    {
        // Iterative cycle detection: a deep chain must not hit any nesting
        // limit (CI runs under XDebug, max_nesting_level 256).
        $steps = [$this->step(1, 'Step 1')];
        for ($i = 2; $i <= 300; ++$i) {
            $steps[] = $this->step($i, "Step {$i}", [$i - 1]);
        }

        self::assertSame([], $this->validator()->problems($steps));
    }

    public function testLongCycleIsDetectedWithoutRecursion(): void
    {
        $steps = [];
        for ($i = 1; $i <= 300; ++$i) {
            $steps[] = $this->step($i, "Step {$i}", [1 === $i ? 300 : $i - 1]);
        }

        $problems = $this->validator()->problems($steps);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Dependency cycle', $problems[0]);
    }
}
