<?php

declare(strict_types=1);

namespace App\Tests\Functional\Context;

use App\Context\Grounding;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\RunEngine\PromptCompiler;
use App\RunEngine\PromptTemplate;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The grounding block and the prompt knobs as the *container* builds them.
 *
 * The unit tests construct these objects directly, which cannot catch the
 * bug this file exists for: Grounding used to be autowired with no
 * arguments, so it silently defaulted to the server clock — every run was
 * told it was UTC while TASKLOOM_TIMEZONE said otherwise, and nothing in
 * the wiring pointed at the variable at all. A service that *can* be
 * constructed with no arguments is exactly the kind of omission that never
 * fails loudly.
 *
 * So these assertions go through the real service container.
 */
final class GroundingWiringTest extends KernelTestCase
{
    /**
     * The block the container renders speaks TASKLOOM_TIMEZONE.
     *
     * The test environment pins America/Chicago (`.env.test`), while the
     * PHP default timezone in CI is UTC — so if the wiring regresses to the
     * old no-argument autowiring, this renders UTC and fails.
     */
    public function testTheContainerWiresTheDeploymentTimezoneIntoGrounding(): void
    {
        $grounding = $this->grounding();

        self::assertSame('America/Chicago', $_SERVER['TASKLOOM_TIMEZONE'] ?? null, 'the test env must pin a non-UTC zone for this test to mean anything');

        $rendered = $grounding->render();

        self::assertStringContainsString('(America/Chicago)', $rendered);
        self::assertStringNotContainsString('(UTC)', $rendered, 'the server clock must not leak into the block');
    }

    /**
     * And the wall-clock time is the deployment's, not the server's: the
     * block's time must differ from the same instant expressed in UTC
     * whenever the zones do.
     */
    public function testTheBlocksClockIsTheDeploymentClock(): void
    {
        $rendered = $this->grounding()->render();

        preg_match('/^Current time: (\d{2}:\d{2}) \(/m', $rendered, $matches);
        self::assertNotEmpty($matches, 'the block states a time');

        $utcNow = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('H:i');

        // America/Chicago is UTC-5 or UTC-6, so the rendered time is never
        // the UTC one. (A one-in-a-day false pass is impossible: the zones
        // differ by 5–6 hours, not minutes.)
        self::assertNotSame($utcNow, $matches[1], 'the block renders the deployment wall clock, not the container clock');
    }

    /**
     * The prompt knobs resolve through the container with their documented
     * defaults when unset: this deployment configures none of them, and the
     * result must be the historic prompt.
     */
    public function testUnsetPromptKnobsResolveToTheBuiltInDefaults(): void
    {
        $template = static::getContainer()->get(PromptTemplate::class);

        self::assertInstanceOf(PromptTemplate::class, $template);
        self::assertStringContainsString('You are an autonomous task executor.', $template->preamble());
        self::assertStringContainsString('NO tool calls', $template->completion());
        // The two duplicate-rendering toggles are pinned OFF in .env.test, so
        // this asserts the wiring resolves them, not the built-in default.
        self::assertTrue($template->listsTools());
    }

    /**
     * The default (unset) really is off, on a bare template — the built-in
     * behavior, independent of any .env pin a test harness may set.
     */
    public function testTheBuiltInDefaultsDoNotDuplicate(): void
    {
        $template = new PromptTemplate();

        self::assertFalse($template->listsTools());
        self::assertFalse($template->includesTaskBriefInSystem());
    }

    /**
     * TASKLOOM_LOCATION reaches the block through the container, as its own
     * line, in the same once-per-run block as the clock and units.
     *
     * The test env pins a location (`.env.test`) for exactly this reason: a
     * default:: knob that is never wired, or wired into the wrong argument,
     * resolves to null and says nothing — the failure looks identical to
     * "the operator did not configure it".
     */
    public function testTheContainerWiresTheDeploymentLocationIntoGrounding(): void
    {
        self::assertSame('Chicago', $_SERVER['TASKLOOM_LOCATION'] ?? null, 'the test env must pin a location for this test to mean anything');

        $rendered = $this->grounding()->render();

        self::assertStringContainsString('Location: Chicago', $rendered);
        self::assertStringEndsWith('Location: Chicago', rtrim($rendered), 'location is the last line of the block');
    }

    /**
     * ...and it keeps its place when the block is assembled into a prompt:
     * the whole point of the knob is that the model reads it once, in the
     * grounding section, not detached somewhere else in the head.
     */
    public function testTheLocationReachesTheCompiledPrompt(): void
    {
        $compiler = static::getContainer()->get(PromptCompiler::class);
        self::assertInstanceOf(PromptCompiler::class, $compiler);

        $task = new Task(
            'Morning Briefing',
            'Compose the full briefing.',
            TaskKind::Run,
            ToolboxMode::Explicit,
            [],
            TaskAuthor::User,
        );

        $system = $compiler->compile($task, [])['system'];

        $groundingAt = strpos($system, '## Grounding');
        self::assertNotFalse($groundingAt, 'grounding renders');

        $locationAt = strpos($system, 'Location: Chicago');
        self::assertNotFalse($locationAt, 'the configured location reaches the prompt');
        self::assertGreaterThan($groundingAt, $locationAt, 'the location line lives inside the grounding block, not elsewhere in the head');
    }

    /**
     * The compiler the engine actually runs is the wired one — same object
     * graph the workers boot with, not a hand-built stand-in.
     */
    public function testTheCompilerIsWiredWithBothCollaborators(): void
    {
        $compiler = static::getContainer()->get(PromptCompiler::class);

        self::assertInstanceOf(PromptCompiler::class, $compiler);

        $reflection = new \ReflectionClass($compiler);
        self::assertInstanceOf(Grounding::class, $reflection->getProperty('grounding')->getValue($compiler));
        self::assertInstanceOf(PromptTemplate::class, $reflection->getProperty('template')->getValue($compiler));
    }

    private function grounding(): Grounding
    {
        $grounding = static::getContainer()->get(Grounding::class);
        self::assertInstanceOf(Grounding::class, $grounding);

        return $grounding;
    }
}
