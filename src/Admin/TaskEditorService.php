<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\SessionKindUnsupportedException;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Repository\StepRepository;
use App\Repository\ToolRepository;
use App\Scheduler\ScheduleExpression;
use App\Scheduler\ScheduleFormatException;
use App\Scheduler\SchedulePreset;
use App\Scheduler\SchedulePresetMatch;
use App\StepModel\StepFormatException;
use App\StepModel\StepGraphCodec;
use Doctrine\ORM\EntityNotFoundException;

/**
 * The admin UI's task authoring surface (SPEC §8, ROADMAP v1.x): create and
 * edit tasks — fields, step graph, schedule — from the browser, as the human
 * author.
 *
 * Before this, authoring existed only as MCP tools and the console; a human
 * who wanted to change a task needed an agent or SQL. The write still goes
 * through TaskCrud — the same gated path the MCP tools use (SPEC §4.3) — so
 * the human's own writes land disabled exactly like an agent's, and are then
 * enabled deliberately from the approval queue. Authoring and approval stay
 * separate acts even for the person who authored the task: that is what
 * makes the queue a queue rather than a formality.
 *
 * This class owns the translation both ways:
 *
 *  - form values → persistence, by building the wire-format step graph
 *    (SPEC §13.2) and handing it to TaskCrud, which validates and translates
 *    it to depends_on edges inside its transaction;
 *  - a stored task → form values, so editing reopens with what is actually
 *    saved — including which preset authored the schedule, when one did.
 */
final class TaskEditorService
{
    public function __construct(
        private readonly TaskCrud $crud,
        private readonly StepRepository $steps,
        private readonly ToolRepository $tools,
        private readonly StepGraphCodec $codec,
        private readonly ScheduleExpression $schedules,
    ) {
    }

    /**
     * A new task's form values — the defaults a fresh editor opens on.
     *
     * @return array<string, mixed>
     */
    public function blankValues(): array
    {
        return [
            'title' => '',
            'brief' => '',
            'kind' => TaskKind::Run->value,
            'toolbox_mode' => ToolboxMode::Tags->value,
            'toolbox' => [],
            'schedule_mode' => TaskEditorSubmission::SCHEDULE_MODE_NONE,
            'schedule_preset' => SchedulePreset::Daily->value,
            'schedule_time' => '07:00',
            'schedule_weekday' => '1',
            'schedule_day_of_month' => '1',
            'schedule_custom' => '',
            'steps' => [],
        ];
    }

    /**
     * An existing task's form values, so editing reopens on what is saved.
     *
     * The step graph is rendered through the codec (stored depends_on edges →
     * nested levels, SPEC §13.2), never reconstructed from position: what the
     * editor shows is what the engine would run. A graph the codec cannot
     * express faithfully — hand-edited edges that skip levels — renders to its
     * leveled form, which is the same graph the wire format can round-trip;
     * the raw edges remain visible on the detail page's step records.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(Task $task): array
    {
        $steps = $this->codec->render($this->steps->findForTask($task));

        return [
            'title' => $task->getTitle(),
            'brief' => $task->getBrief(),
            'kind' => $task->getKind()->value,
            'toolbox_mode' => $task->getToolboxMode()->value,
            'toolbox' => $task->getToolbox(),
            'steps' => $steps,
            ...$this->scheduleValuesFor($task->getSchedule()),
        ];
    }

    /**
     * The schedule block of the form, seeded from a stored expression: the
     * preset that composed it when one is recognised, a custom expression
     * otherwise. Never guesses — an unrecognised expression reopens as custom,
     * so saving it again cannot silently rewrite it into a preset.
     *
     * @return array{schedule_mode: string, schedule_preset: string, schedule_time: string, schedule_weekday: string, schedule_day_of_month: string, schedule_custom: string}
     */
    public function scheduleValuesFor(?string $schedule): array
    {
        $normalized = ScheduleExpression::normalize($schedule);

        $values = [
            'schedule_mode' => TaskEditorSubmission::SCHEDULE_MODE_NONE,
            'schedule_preset' => SchedulePreset::Daily->value,
            'schedule_time' => '07:00',
            'schedule_weekday' => '1',
            'schedule_day_of_month' => '1',
            'schedule_custom' => '',
        ];

        if (null === $normalized) {
            return $values;
        }

        $match = SchedulePreset::fromExpression($normalized);
        if (null === $match) {
            $values['schedule_mode'] = TaskEditorSubmission::SCHEDULE_MODE_CUSTOM;
            $values['schedule_custom'] = $normalized;

            return $values;
        }

        $values['schedule_mode'] = TaskEditorSubmission::SCHEDULE_MODE_PRESET;
        $values['schedule_preset'] = $match->preset->value;
        if ('' !== $match->time) {
            $values['schedule_time'] = $match->time;
        }
        if (null !== $match->weekday) {
            $values['schedule_weekday'] = (string) $match->weekday;
        }
        if (null !== $match->dayOfMonth) {
            $values['schedule_day_of_month'] = (string) $match->dayOfMonth;
        }

        return $values;
    }

    /**
     * Parse a raw form submission into a normalized submission bound to the
     * same validation the save path applies. Used by both save and the live
     * schedule preview, so the preview can never bless something the save
     * would refuse (SPEC §14.5).
     *
     * @param array<array-key, mixed> $input
     */
    public function parse(array $input): TaskEditorSubmission
    {
        return TaskEditorSubmission::fromArray($input, $this->schedules);
    }

    /**
     * Persist a submission.
     *
     * Creating always lands a disabled draft (TaskCrud's gate). Updating a
     * draft edits it in place; updating an **enabled** task creates a disabled
     * replacement draft and leaves the original running, untouched — for the
     * human exactly as for an agent (SPEC §4.4: enabled tasks are immutable
     * for everyone).
     *
     * @throws TaskEditorException when the submission cannot be persisted
     */
    public function save(?Task $task, TaskEditorSubmission $submission): Task
    {
        $values = $submission->values;

        try {
            $kind = TaskKind::from($values['kind']);
            $toolboxMode = ToolboxMode::from($values['toolbox_mode']);
        } catch (\ValueError $e) {
            // Unreachable via parse(): both enums are checked there and fall
            // back to their first case. Guard anyway — a silent wrong value
            // here would be a task authored differently than submitted.
            throw new TaskEditorException('Unsupported kind or toolbox mode.', 0, $e);
        }

        try {
            if (!$task instanceof Task) {
                return $this->crud->create(
                    $values['title'],
                    $values['brief'],
                    $kind,
                    $toolboxMode,
                    $values['toolbox'],
                    $submission->schedule(),
                    $submission->steps(),
                    TaskAuthor::User,
                );
            }

            return $this->crud->update($task->getId() ?? throw new TaskEditorException('Cannot update an unsaved task.'), [
                'title' => $values['title'],
                'brief' => $values['brief'],
                'kind' => $kind,
                'toolbox_mode' => $toolboxMode,
                'toolbox' => $values['toolbox'],
                'schedule' => $submission->schedule(),
                'steps' => $submission->steps(),
            ], TaskAuthor::User);
        } catch (StepFormatException|ScheduleFormatException|EntityNotFoundException|SessionKindUnsupportedException $e) {
            // One catch: all four are "the form said something the store
            // refused", and the message is already written for a human.
            throw new TaskEditorException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The catalog the toolbox picker offers: every discovered tag, and every
     * tool with its server name, so the explicit list can be chosen by hand
     * instead of typed from memory.
     *
     * Both pickers — the task's own and every step's — render this same
     * list, in the catalog's canonical order (server, then tool), so a tool
     * is where the operator last saw it. Tags are alphabetical: a flat
     * vocabulary with no server axis.
     *
     * @return array{tags: list<string>, tools: list<Tool>}
     */
    public function catalog(): array
    {
        $tags = [];
        foreach ($this->tools->findAllOrdered() as $tool) {
            foreach ($tool->getTags() as $tag) {
                $tags[$tag] = true;
            }
        }

        $tags = array_keys($tags);
        sort($tags);

        return [
            'tags' => $tags,
            'tools' => $this->tools->findAllOrdered(),
        ];
    }

    /**
     * The presets the picker renders, with the fields each one needs
     * (SPEC §14: the composition happens server-side from these fields).
     *
     * @return list<SchedulePreset>
     */
    public function schedulePresets(): array
    {
        return SchedulePreset::cases();
    }

    /**
     * Recognise a stored schedule for display — used by the detail page's
     * schedule block and the editor's summary line.
     */
    public function presetMatch(?string $schedule): ?SchedulePresetMatch
    {
        $normalized = ScheduleExpression::normalize($schedule);

        return null === $normalized ? null : SchedulePreset::fromExpression($normalized);
    }

    /**
     * A task's steps in display order, for the read-only summary the editor
     * shows above the builder.
     *
     * @return list<Step>
     */
    public function stepsFor(Task $task): array
    {
        return $this->steps->findForTask($task);
    }
}
