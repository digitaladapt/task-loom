<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\TaskKind;
use App\Scheduler\ScheduleExpression;
use App\Scheduler\ScheduleFormatException;
use App\Scheduler\SchedulePreset;

/**
 * One admin-editor form submission (SPEC §8): the nested HTML form data,
 * normalized into the shapes the rest of the app already speaks — and every
 * problem found, keyed by the field that caused it.
 *
 * This is the authoring boundary for the human path, the same role
 * StepGraphCodec::parse() plays for the MCP path (SPEC §13.2). It exists
 * because the two inputs are genuinely different: a form POST carries
 * checkbox arrays, sparse level/step indices (a removed level leaves gaps in
 * what JavaScript submits), and preset fields whose cron is composed
 * server-side. The *output*, though, is deliberately the same wire format the
 * codec and the persistence layer already consume: nested level/step arrays
 * of title + brief + toolbox_mode + toolbox.
 *
 * Two rules the rest of the codebase would otherwise have to re-derive:
 *
 *  - **Re-indexing.** Levels and steps are keyed by whatever index the
 *    browser sent (`steps[3][7][title]`); both are re-indexed in sorted key
 *    order, so the persisted graph follows the order the human sees.
 *  - **Composition, not transcription.** A preset schedule is composed to
 *    cron here, by SchedulePreset — the same enum the picker renders from —
 *    so the picker, the preview, and the stored expression can never
 *    disagree about what "every weekday at 8am" means.
 *
 * Validation is total: it reports every problem at once with the values
 * intact, so one pass is enough to fix a submission — the same discipline as
 * the codec's indexed diagnosis. Cron validity is judged by
 * ScheduleExpression, the same authority create/update and the enable gate
 * use (SPEC §14.5): the editor adds a friendlier front door, never a second
 * opinion.
 */
final readonly class TaskEditorSubmission
{
    public const string SCHEDULE_MODE_NONE = 'none';
    public const string SCHEDULE_MODE_PRESET = 'preset';
    public const string SCHEDULE_MODE_CUSTOM = 'custom';

    /**
     * @param array{
     *     title: string,
     *     brief: string,
     *     kind: string,
     *     toolbox_mode: string,
     *     toolbox: list<string>,
     *     schedule_mode: string,
     *     schedule_preset: string,
     *     schedule_time: string,
     *     schedule_weekday: string,
     *     schedule_day_of_month: string,
     *     schedule_custom: string,
     *     steps: list<list<array{title: string, brief: string, toolbox_mode: string, toolbox: list<string>}>>,
     * } $values  the submission, normalized and re-indexed — always renderable, valid or not
     * @param array<string, string> $errors field key => human-readable problem; keys are
     *                                      'title', 'brief', 'kind', 'toolbox', 'schedule',
     *                                      'steps.<level>', 'steps.<level>.<step>.<field>'
     */
    private function __construct(
        public array $values,
        public array $errors,
        private ?string $schedule,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input raw form data ($request->request->all())
     */
    public static function fromArray(array $input, ScheduleExpression $schedules): self
    {
        $errors = [];

        $title = self::text($input['title'] ?? '');
        if ('' === $title) {
            $errors['title'] = 'A title is required.';
        } elseif (mb_strlen($title) > 200) {
            $errors['title'] = 'Keep the title to 200 characters or fewer (the task table stores 200).';
        }

        $brief = self::text($input['brief'] ?? '');
        if ('' === $brief) {
            $errors['brief'] = 'A brief is required — it is the prompt the model receives.';
        }

        $kind = self::text($input['kind'] ?? TaskKind::Run->value);
        if (null === TaskKind::tryFrom($kind)) {
            $errors['kind'] = \sprintf('Unknown task kind "%s".', $kind);
            $kind = TaskKind::Run->value;
        }

        // The picker's fields -> a declaration. One implementation, shared
        // with the chat surface: two parsers would be two answers to "what
        // does this checked box mean?".
        $toolbox = ToolboxSelection::parse($input);
        $toolboxMode = $toolbox->mode->value;
        if (null !== $toolbox->error) {
            $errors['toolbox'] = $toolbox->error;
        }

        [$steps, $stepErrors] = self::parseSteps($input['steps'] ?? []);
        $errors += $stepErrors;

        [$scheduleValues, $schedule, $scheduleErrors] = self::parseSchedule($input, $schedules);
        $errors += $scheduleErrors;

        return new self([
            'title' => $title,
            'brief' => $brief,
            'kind' => $kind,
            'toolbox_mode' => $toolboxMode,
            'toolbox' => $toolbox->declared,
            'steps' => $steps,
            ...$scheduleValues,
        ], $errors, $schedule);
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    /**
     * The schedule to persist: null for "manual only", otherwise a validated,
     * normalized cron expression. Composed once at parse time, so the value
     * the human previewed is the value that gets saved.
     */
    public function schedule(): ?string
    {
        return $this->schedule;
    }

    /**
     * The schedule block's own problems — the 'schedule' key plus any key
     * beneath it. The live preview endpoint reports exactly these, so what the
     * browser shows while typing and what the save refuses are one list.
     *
     * @return list<string>
     */
    public function scheduleProblems(): array
    {
        $problems = [];
        foreach ($this->errors as $key => $message) {
            if ('schedule' === $key || str_starts_with($key, 'schedule.')) {
                $problems[] = $message;
            }
        }

        return $problems;
    }

    /**
     * The step graph in the authoring/wire format (SPEC §13.2) — exactly what
     * StepGraphCodec::parse() and the persistence layer expect, and what the
     * codec would render back out for the same graph.
     *
     * @return list<list<array{title: string, brief: string, toolbox_mode: string, toolbox: list<string>}>>
     */
    public function steps(): array
    {
        return $this->values['steps'];
    }

    /**
     * Steps arrive as steps[level][step][field]. Both indices are re-based in
     * sorted key order: removing a level or a step in the browser leaves gaps
     * in what is submitted, and the codec requires dense lists.
     *
     * @return array{
     *     0: list<list<array{title: string, brief: string, toolbox_mode: string, toolbox: list<string>}>>,
     *     1: array<string, string>,
     * }
     */
    private static function parseSteps(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [[], []];
        }

        ksort($raw);

        $errors = [];
        $levels = [];
        $levelIndex = 0;

        foreach ($raw as $rawLevel) {
            if (!\is_array($rawLevel)) {
                continue;
            }
            ksort($rawLevel);

            $steps = [];
            $stepIndex = 0;
            foreach ($rawLevel as $rawStep) {
                if (!\is_array($rawStep)) {
                    continue;
                }

                $steps[] = self::parseStep($rawStep, \sprintf('steps.%d.%d', $levelIndex, $stepIndex), $errors);
                ++$stepIndex;
            }

            if ([] === $steps) {
                $errors[\sprintf('steps.%d', $levelIndex)] = \sprintf(
                    'Level %d has no steps. Add one, or remove the level — every level needs at least one step (SPEC §13.2).',
                    $levelIndex + 1,
                );
            }

            $levels[] = $steps;
            ++$levelIndex;
        }

        // One empty level is the browser's way of saying "no steps at all":
        // drop it silently rather than making the human argue with an empty box.
        if (1 === \count($levels) && [] === $levels[0]) {
            unset($errors['steps.0']);
            $levels = [];
        }

        return [$levels, $errors];
    }

    /**
     * @param array<array-key, mixed> $rawStep
     * @param array<string, string>   $errors  collected by key
     *
     * @return array{title: string, brief: string, toolbox_mode: string, toolbox: list<string>}
     */
    private static function parseStep(array $rawStep, string $path, array &$errors): array
    {
        $title = self::text($rawStep['title'] ?? '');
        if ('' === $title) {
            $errors[$path.'.title'] = 'A step title is required.';
        } elseif (mb_strlen($title) > 200) {
            $errors[$path.'.title'] = 'Keep the step title to 200 characters or fewer.';
        }

        $brief = self::text($rawStep['brief'] ?? '');
        if ('' === $brief) {
            $errors[$path.'.brief'] = 'A step brief is required — it is the prompt this step\'s run receives.';
        }

        $toolbox = ToolboxSelection::parse($rawStep);
        $toolboxMode = $toolbox->mode->value;
        if (null !== $toolbox->error) {
            $errors[$path.'.toolbox'] = $toolbox->error;
        }

        return [
            'title' => $title,
            'brief' => $brief,
            'toolbox_mode' => $toolboxMode,
            'toolbox' => $toolbox->declared,
        ];
    }

    /**
     * The schedule block, normalized. An incomplete preset or an invalid
     * custom expression is reported here — the same refusal the create/update
     * boundary and the enable gate apply (SPEC §14.5), moved to where the
     * human can act on it.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array{
     *     0: array{
     *         schedule_mode: string,
     *         schedule_preset: string,
     *         schedule_time: string,
     *         schedule_weekday: string,
     *         schedule_day_of_month: string,
     *         schedule_custom: string,
     *     },
     *     1: ?string,
     *     2: array<string, string>,
     * }
     */
    private static function parseSchedule(array $input, ScheduleExpression $schedules): array
    {
        $mode = self::text($input['schedule_mode'] ?? self::SCHEDULE_MODE_NONE);
        if (!\in_array($mode, [self::SCHEDULE_MODE_NONE, self::SCHEDULE_MODE_PRESET, self::SCHEDULE_MODE_CUSTOM], true)) {
            $mode = self::SCHEDULE_MODE_NONE;
        }

        $preset = self::text($input['schedule_preset'] ?? '');
        if ('' !== $preset && null === SchedulePreset::tryFrom($preset)) {
            $preset = '';
        }

        $values = [
            'schedule_mode' => $mode,
            'schedule_preset' => $preset,
            'schedule_time' => self::text($input['schedule_time'] ?? '07:00'),
            'schedule_weekday' => self::text($input['schedule_weekday'] ?? '1'),
            'schedule_day_of_month' => self::text($input['schedule_day_of_month'] ?? '1'),
            'schedule_custom' => self::text($input['schedule_custom'] ?? ''),
        ];

        $errors = [];
        $expression = null;

        if (self::SCHEDULE_MODE_PRESET === $mode) {
            if ('' === $preset) {
                // Nothing picked: fall to the picker's default rather than an
                // error the human cannot place.
                $preset = SchedulePreset::Daily->value;
                $values['schedule_preset'] = $preset;
            }

            $selected = SchedulePreset::tryFrom($preset);
            \assert(null !== $selected);
            try {
                $expression = $selected->compose(
                    $values['schedule_time'],
                    $values['schedule_weekday'],
                    $values['schedule_day_of_month'],
                );
            } catch (\InvalidArgumentException $e) {
                $errors['schedule'] = $e->getMessage();
            }
        }

        if (self::SCHEDULE_MODE_CUSTOM === $mode) {
            $expression = ScheduleExpression::normalize($values['schedule_custom']);
            if (null === $expression) {
                $errors['schedule'] = 'Enter a cron expression, or switch back to one of the offered schedules.';
            }
        }

        if (null !== $expression) {
            try {
                $schedules->assertValid($expression);
            } catch (ScheduleFormatException $e) {
                $errors['schedule'] = $e->getMessage();
                $expression = null;
            }
        }

        return [$values, $expression, $errors];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
