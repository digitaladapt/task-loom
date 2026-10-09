<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\TaskEditorSubmission;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Scheduler\ScheduleExpression;
use PHPUnit\Framework\TestCase;

/**
 * The admin editor's form parser (SPEC §8): the nested HTML form data,
 * normalized into the wire format the persistence layer already speaks.
 *
 * Two things are load-bearing and asserted hard here:
 *
 *  - **Re-indexing.** A browser that removed a level or a step submits a
 *    sparse array. The codec downstream requires dense lists, so the parser
 *    closes the gaps in sorted key order — and must keep the order the human
 *    saw while doing it.
 *  - **The schedule block.** Presets compose server-side, custom expressions
 *    are validated by the same authority the enable gate uses, and an invalid
 *    one is reported rather than persisted.
 */
final class TaskEditorSubmissionTest extends TestCase
{
    private function schedules(): ScheduleExpression
    {
        return new ScheduleExpression();
    }

    /**
     * The minimum a saveable submission needs.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Morning briefing',
            'brief' => 'Compose the briefing.',
            'kind' => 'run',
            'toolbox_mode' => 'tags',
            'toolbox_tags' => ['weather'],
            'schedule_mode' => 'none',
        ], $overrides);
    }

    public function testAFullyFormedSubmissionIsAccepted(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input(), $this->schedules());

        self::assertFalse($submission->hasErrors());
        self::assertSame('Morning briefing', $submission->values['title']);
        self::assertSame(TaskKind::Run->value, $submission->values['kind']);
        self::assertSame(ToolboxMode::Tags->value, $submission->values['toolbox_mode']);
        self::assertSame(['weather'], $submission->values['toolbox']);
        self::assertNull($submission->schedule());
        self::assertSame([], $submission->steps());
    }

    public function testTitleAndBriefAreRequired(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input(['title' => '  ', 'brief' => '']), $this->schedules());

        self::assertTrue($submission->hasErrors());
        self::assertArrayHasKey('title', $submission->errors);
        self::assertArrayHasKey('brief', $submission->errors);
    }

    public function testAnOverlongTitleIsRefusedRatherThanSilentlyTruncated(): void
    {
        $submission = TaskEditorSubmission::fromArray(
            $this->input(['title' => str_repeat('x', 201)]),
            $this->schedules(),
        );

        self::assertArrayHasKey('title', $submission->errors);
        self::assertStringContainsString('200 characters', $submission->errors['title']);
    }

    public function testUnknownEnumsFallBackSoTheFormStaysRenderable(): void
    {
        $submission = TaskEditorSubmission::fromArray(
            $this->input(['kind' => 'nonsense', 'toolbox_mode' => 'also-nonsense']),
            $this->schedules(),
        );

        self::assertTrue($submission->hasErrors());
        self::assertArrayHasKey('kind', $submission->errors);
        self::assertArrayHasKey('toolbox', $submission->errors);
        // Normalized anyway: re-rendering the form must not blow up on the
        // values it is about to print back.
        self::assertSame(TaskKind::Run->value, $submission->values['kind']);
        self::assertSame(ToolboxMode::Tags->value, $submission->values['toolbox_mode']);
    }

    /**
     * docs/design/SESSION_TASKS.md, build order step 1: the enum carries
     * "session", but no engine does — the save is refused beside the field,
     * and the submitted value survives so the select re-renders as chosen.
     */
    public function testTheSessionKindIsRefusedUntilItsEngineExists(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input(['kind' => 'session']), $this->schedules());

        self::assertTrue($submission->hasErrors());
        self::assertArrayHasKey('kind', $submission->errors);
        self::assertStringContainsString('not built yet', $submission->errors['kind']);
        self::assertSame('session', $submission->values['kind'], 'the select re-renders as the human left it');
    }

    /**
     * Only the selected mode's list is honoured: the other picker is hidden
     * in the browser, and a stale value in it must never leak into the saved
     * declaration.
     */
    public function testOnlyTheSelectedToolboxModeIsRead(): void
    {
        $tagsWins = TaskEditorSubmission::fromArray($this->input([
            'toolbox_mode' => 'tags',
            'toolbox_tags' => ['weather'],
            'toolbox_tools' => ['get_weather'],
        ]), $this->schedules());
        self::assertSame(['weather'], $tagsWins->values['toolbox']);

        $toolsWin = TaskEditorSubmission::fromArray($this->input([
            'toolbox_mode' => 'explicit',
            'toolbox_tags' => ['weather'],
            'toolbox_tools' => ['get_weather'],
        ]), $this->schedules());
        self::assertSame(['get_weather'], $toolsWin->values['toolbox']);
    }

    /**
     * The free-text companion exists so a human can declare what the
     * discovered catalog does not carry. It is how the browser re-opens a
     * task whose tools are not in the catalog — without it, reopening and
     * re-saving would silently drop them.
     */
    public function testTheFreeTextCompanionMergesWithTheCheckboxList(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'toolbox_mode' => 'explicit',
            'toolbox_tools' => ['get_weather'],
            'toolbox_tools_extra' => '  get_events , private_tool ,, get_weather ',
        ]), $this->schedules());

        // Order preserved, empties dropped, duplicates collapsed.
        self::assertSame(['get_weather', 'get_events', 'private_tool'], $submission->values['toolbox']);
    }

    public function testStepsAreParsedIntoTheWireFormat(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'steps' => [
                0 => [
                    0 => ['title' => 'Weather', 'brief' => 'Fetch it.', 'toolbox_mode' => 'explicit', 'toolbox_tools' => ['get_weather']],
                    1 => ['title' => 'Calendar', 'brief' => 'Fetch it.', 'toolbox_mode' => 'tags', 'toolbox_tags' => ['calendar']],
                ],
                1 => [
                    0 => ['title' => 'Summary', 'brief' => 'Summarize.', 'toolbox_mode' => 'tags', 'toolbox_tags' => []],
                ],
            ],
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());

        $steps = $submission->steps();
        self::assertCount(2, $steps);
        self::assertCount(2, $steps[0]);
        self::assertCount(1, $steps[1]);
        self::assertSame('Weather', $steps[0][0]['title']);
        self::assertSame(['get_weather'], $steps[0][0]['toolbox']);
        self::assertSame('Calendar', $steps[0][1]['title']);
        self::assertSame(['calendar'], $steps[0][1]['toolbox']);
        self::assertSame('Summary', $steps[1][0]['title']);
    }

    /**
     * Removing a level or a step in the browser leaves gaps in the submitted
     * indices. The parser closes them in sorted key order — and keeps the
     * order the human saw, which is the part that is easy to get wrong.
     */
    public function testSparseIndicesAreReIndexedInDisplayOrder(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'steps' => [
                3 => [ // levels 0-2 were removed
                    7 => ['title' => 'First shown', 'brief' => 'b', 'toolbox_mode' => 'tags'],
                    2 => ['title' => 'Second shown', 'brief' => 'b', 'toolbox_mode' => 'tags'],
                ],
                9 => [
                    5 => ['title' => 'Third shown', 'brief' => 'b', 'toolbox_mode' => 'tags'],
                ],
            ],
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());

        $steps = $submission->steps();
        // Dense lists, sorted-key order preserved.
        self::assertSame([0, 1], array_keys($steps));
        self::assertSame([0, 1], array_keys($steps[0]));
        self::assertSame('Second shown', $steps[0][0]['title']);
        self::assertSame('First shown', $steps[0][1]['title']);
        self::assertSame('Third shown', $steps[1][0]['title']);
    }

    public function testAStepMissingItsTitleOrBriefIsReportedByIndex(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'steps' => [
                0 => [
                    0 => ['title' => '', 'brief' => 'has a brief', 'toolbox_mode' => 'tags'],
                    1 => ['title' => 'Has a title', 'brief' => '', 'toolbox_mode' => 'tags'],
                ],
            ],
        ]), $this->schedules());

        self::assertArrayHasKey('steps.0.0.title', $submission->errors);
        self::assertArrayHasKey('steps.0.1.brief', $submission->errors);
    }

    /**
     * An empty level is a hole in the graph (SPEC §13.2: every level needs at
     * least one step), so it is refused — but a *single* empty level is the
     * browser's way of saying "no steps at all" and is dropped silently,
     * because that is the zero-step task, which is valid.
     */
    public function testOneEmptyLevelMeansNoSteps(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'steps' => [0 => []],
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());
        self::assertSame([], $submission->steps());
    }

    public function testAnEmptyLevelAlongsideRealOnesIsRefused(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'steps' => [
                0 => [0 => ['title' => 'Real', 'brief' => 'b', 'toolbox_mode' => 'tags']],
                1 => [],
            ],
        ]), $this->schedules());

        self::assertArrayHasKey('steps.1', $submission->errors);
        self::assertStringContainsString('no steps', $submission->errors['steps.1']);
    }

    public function testTheWholeStepsListAbsentMeansNoSteps(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input(), $this->schedules());

        self::assertSame([], $submission->steps());
        self::assertFalse($submission->hasErrors());
    }

    /**
     * Presets compose server-side, so the picker, the preview, and the stored
     * expression cannot disagree about what "every weekday at 6:30am" means.
     */
    public function testAPresetComposesToItsCronExpression(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'weekdays',
            'schedule_time' => '06:30',
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());
        self::assertSame('30 6 * * 1-5', $submission->schedule());
    }

    public function testACustomExpressionIsNormalizedAndStored(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'custom',
            'schedule_custom' => '  0 8,12 * * *  ',
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());
        self::assertSame('0 8,12 * * *', $submission->schedule());
    }

    public function testManualOnlyHasNoSchedule(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'none',
            'schedule_custom' => '0 8 * * *', // present but not the selected mode
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());
        self::assertNull($submission->schedule());
    }

    /**
     * The editor validates with the same authority the enable gate uses
     * (SPEC §14.5): the friendlier front door, never a second opinion.
     */
    public function testAnInvalidCustomExpressionIsRefused(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'custom',
            'schedule_custom' => 'every morning please',
        ]), $this->schedules());

        self::assertTrue($submission->hasErrors());
        self::assertNull($submission->schedule());
        self::assertCount(1, $submission->scheduleProblems());
        self::assertStringContainsString('not a valid cron expression', $submission->scheduleProblems()[0]);
    }

    public function testAnEmptyCustomExpressionIsRefusedWithItsOwnMessage(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'custom',
            'schedule_custom' => '',
        ]), $this->schedules());

        self::assertStringContainsString('cron expression', $submission->scheduleProblems()[0]);
    }

    public function testAnIncompletePresetIsRefused(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'weekly',
            'schedule_time' => '09:00',
            'schedule_weekday' => '', // no day picked
        ]), $this->schedules());

        self::assertStringContainsString('day of the week', $submission->scheduleProblems()[0]);
    }

    public function testAnUnrecognisedPresetNameFallsBackToTheDefaultRatherThanErroring(): void
    {
        // A stale or hand-tampered preset value: the human cannot fix a name
        // they never chose, so the picker's default is used.
        $submission = TaskEditorSubmission::fromArray($this->input([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'does_not_exist',
            'schedule_time' => '07:00',
        ]), $this->schedules());

        self::assertFalse($submission->hasErrors());
        self::assertSame('0 7 * * *', $submission->schedule());
        self::assertSame('daily', $submission->values['schedule_preset']);
    }

    /**
     * scheduleProblems() must report exactly the schedule block's problems,
     * not the whole form's: it is what the live preview endpoint returns, and
     * the browser renders it next to the schedule fields.
     */
    public function testScheduleProblemsDoesNotReportUnrelatedFields(): void
    {
        $submission = TaskEditorSubmission::fromArray($this->input([
            'title' => '', // a problem, but not the schedule's
            'schedule_mode' => 'custom',
            'schedule_custom' => 'nope',
        ]), $this->schedules());

        $problems = $submission->scheduleProblems();
        self::assertCount(1, $problems);
        self::assertStringNotContainsString('title', $problems[0]);
    }

    public function testValuesStayRenderableWhenEverythingIsWrong(): void
    {
        $submission = TaskEditorSubmission::fromArray([
            'title' => '',
            'brief' => '',
            'kind' => 'nope',
            'toolbox_mode' => 'nope',
            'steps' => 'not-an-array',
            'schedule_mode' => 'nope',
        ], $this->schedules());

        self::assertTrue($submission->hasErrors());

        // Every key the template reads is present and of the shape it prints
        // back — a re-render must never meet a null it cannot render.
        $expectedKeys = [
            'title', 'brief', 'kind', 'toolbox_mode', 'toolbox', 'steps',
            'schedule_mode', 'schedule_preset', 'schedule_time', 'schedule_weekday',
            'schedule_day_of_month', 'schedule_custom',
        ];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $submission->values, \sprintf('"%s" must survive a failed parse', $key));
        }

        self::assertSame([], $submission->values['steps']);
        self::assertSame('none', $submission->values['schedule_mode'], 'an unknown mode falls back to manual-only');
        self::assertNull($submission->schedule());
    }

    public function testNonScalarInputIsTreatedAsEmptyRatherThanCrashing(): void
    {
        $submission = TaskEditorSubmission::fromArray([
            'title' => ['an', 'array'],
            'brief' => new \stdClass(),
            'toolbox_tags' => 'not-an-array',
            'steps' => [0 => 'not-an-array'],
        ], $this->schedules());

        self::assertTrue($submission->hasErrors());
    }
}
