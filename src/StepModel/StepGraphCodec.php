<?php

declare(strict_types=1);

namespace App\StepModel;

use App\Entity\Step;
use App\Entity\ToolboxMode;

/**
 * The step graph's authoring/wire codec (SPEC §13.2): nested arrays in,
 * depends_on edges stored, nested arrays rendered back out.
 *
 * Wire format — an array of levels, each level an array of step objects:
 *
 *   [
 *     [ {"title": "Weather", "brief": "...", "toolbox_mode": "explicit",
 *        "toolbox": ["get_weather"]}, {"title": "Calendar", ...} ],
 *     [ {"title": "Summary", "brief": "..."} ]
 *   ]
 *
 * Levels run in sequence; steps within a level run in parallel. That
 * encodes as "each step depends on every step of the previous level" —
 * the stored depends_on form. The two forms are equivalent for every
 * graph written through this codec, which is the only way the product
 * writes them (SPEC §13.2).
 *
 * Rendering derives levels from the stored edges (wave-based Kahn: a
 * step's level is one past its deepest dependency), which reproduces the
 * authored levels exactly for codec-written graphs. A graph whose edges
 * skip levels (hand-edited; not expressible in the wire format) renders
 * compressed to its leveled form — display stays total, and the raw
 * edges remain visible on the step records themselves.
 *
 * Step objects carry exactly: title, brief, toolbox_mode (default
 * "explicit"), toolbox (default []). Unknown fields are rejected — a
 * typo like "tooLbox" is an authoring bug, not something to silently
 * drop.
 */
final class StepGraphCodec
{
    /** The fields a wire-format step object may carry. */
    private const STEP_FIELDS = ['title', 'brief', 'toolbox_mode', 'toolbox'];

    /** Matches the step table's title column width. */
    private const TITLE_MAX_LENGTH = 200;

    /**
     * Parse the wire format into persistence-ready specs.
     *
     * Structural problems — wrong shapes, missing or invalid fields,
     * empty levels, unknown fields — are reported all at once, each with
     * its indexed path (steps[1][0].title), so one pass is enough to fix
     * the input.
     *
     * @return list<StepSpec>
     *
     * @throws StepFormatException when any part of the input is malformed
     */
    public function parse(mixed $input): array
    {
        if (null === $input) {
            return [];
        }

        if (!\is_array($input) || !array_is_list($input)) {
            throw new StepFormatException(['steps must be an array of levels — each level an array of step objects.']);
        }

        $problems = [];
        $specs = [];

        foreach ($input as $levelIndex => $level) {
            if (!\is_array($level) || !array_is_list($level)) {
                $problems[] = \sprintf('steps[%d] must be an array of steps.', $levelIndex);

                continue;
            }

            if ([] === $level) {
                $problems[] = \sprintf('steps[%d] is empty — every level needs at least one step.', $levelIndex);

                continue;
            }

            foreach ($level as $stepIndex => $step) {
                $spec = $this->parseStep($step, \sprintf('steps[%d][%d]', $levelIndex, $stepIndex), $levelIndex, $problems);
                if (null !== $spec) {
                    $specs[] = $spec;
                }
            }
        }

        if ([] !== $problems) {
            throw new StepFormatException($problems);
        }

        return $specs;
    }

    /**
     * Render stored steps back to the wire format (SPEC §13.2). Levels
     * derive from the stored edges; order within a level follows the
     * incoming order (position order, via StepRepository::findForTask()).
     *
     * @param list<Step> $steps
     *
     * @return list<list<array{title: string, brief: string, toolbox_mode: string, toolbox: list<string>}>>
     */
    public function render(array $steps): array
    {
        if ([] === $steps) {
            return [];
        }

        $levels = $this->levels($steps);

        /** @var array<int, list<Step>> $groups */
        $groups = [];
        foreach ($steps as $step) {
            $id = $step->getId();
            $groups[null !== $id ? ($levels[$id] ?? 0) : 0][] = $step;
        }
        ksort($groups);

        $rendered = [];
        foreach ($groups as $group) {
            $rendered[] = array_map(
                static fn (Step $step): array => [
                    'title' => $step->getTitle(),
                    'brief' => $step->getBrief(),
                    'toolbox_mode' => $step->getToolboxMode()->value,
                    'toolbox' => $step->getToolbox(),
                ],
                $group,
            );
        }

        return $rendered;
    }

    /**
     * Level index per step id: 0 for steps with no in-task dependencies,
     * otherwise one past the deepest dependency. Wave-based Kahn — the
     * longest-path leveling; recreates the authored levels exactly for
     * codec-written graphs.
     *
     * This is a display/derivation pass, not validation
     * (StepGraphValidator owns that, SPEC §13.2): edges that do not
     * resolve (dangling ids, self-references) are ignored, and steps left
     * unleveled by a cycle land one past the deepest resolved level, so
     * display stays total for invalid drafts too.
     *
     * @param list<Step> $steps
     *
     * @return array<int, int> step id → level
     */
    public function levels(array $steps): array
    {
        /** @var array<int, Step> $byId */
        $byId = [];
        foreach ($steps as $step) {
            $id = $step->getId();
            if (null !== $id) {
                $byId[$id] = $step;
            }
        }

        /** @var array<int, int> $pending unresolved dependency count per step */
        $pending = [];
        /** @var array<int, list<int>> $dependents steps that depend on a given step */
        $dependents = [];

        foreach ($byId as $id => $step) {
            $deps = [];
            foreach ($step->getDependsOn() as $dep) {
                if ($dep !== $id && isset($byId[$dep]) && !\in_array($dep, $deps, true)) {
                    $deps[] = $dep;
                }
            }
            $pending[$id] = \count($deps);
            foreach ($deps as $dep) {
                $dependents[$dep][] = $id;
            }
        }

        /** @var array<int, int> $levels */
        $levels = [];
        /** @var list<int> $wave */
        $wave = [];
        foreach ($pending as $id => $count) {
            if (0 === $count) {
                $levels[$id] = 0;
                $wave[] = $id;
            }
        }

        while ([] !== $wave) {
            $next = [];
            foreach ($wave as $id) {
                foreach ($dependents[$id] ?? [] as $dependent) {
                    if (0 === --$pending[$dependent]) {
                        $levels[$dependent] = $levels[$id] + 1;
                        $next[] = $dependent;
                    }
                }
            }
            $wave = $next;
        }

        if (\count($levels) < \count($byId)) {
            // Cycle in the graph (invalid; a draft pending fixes): place
            // every unresolvable step one past the deepest resolved level.
            $fallback = ([] !== $levels ? max($levels) : -1) + 1;
            foreach (array_keys($byId) as $id) {
                if (!isset($levels[$id])) {
                    $levels[$id] = $fallback;
                }
            }
        }

        return $levels;
    }

    /**
     * @param list<string> $problems
     */
    private function parseStep(mixed $step, string $path, int $level, array &$problems): ?StepSpec
    {
        if ($step instanceof \stdClass) {
            $step = (array) $step;
        }

        if (!\is_array($step) || array_is_list($step)) {
            $problems[] = \sprintf('%s must be an object with "title" and "brief".', $path);

            return null;
        }

        $problemCount = \count($problems);

        foreach (array_keys($step) as $field) {
            if (!\in_array($field, self::STEP_FIELDS, true)) {
                $problems[] = \sprintf('%s has unknown field "%s" — allowed: %s.', $path, $field, implode(', ', self::STEP_FIELDS));
            }
        }

        $title = $step['title'] ?? null;
        if (!\is_string($title) || '' === trim($title)) {
            $problems[] = \sprintf('%s.title must be a non-empty string.', $path);
        } elseif (mb_strlen(trim($title)) > self::TITLE_MAX_LENGTH) {
            $problems[] = \sprintf('%s.title is longer than %d characters.', $path, self::TITLE_MAX_LENGTH);
        }

        $brief = $step['brief'] ?? null;
        if (!\is_string($brief) || '' === trim($brief)) {
            $problems[] = \sprintf('%s.brief must be a non-empty string.', $path);
        }

        $toolboxMode = ToolboxMode::Explicit;
        if (\array_key_exists('toolbox_mode', $step)) {
            $rawMode = $step['toolbox_mode'];
            $parsedMode = \is_string($rawMode) ? ToolboxMode::tryFrom($rawMode) : null;
            if (null === $parsedMode) {
                $problems[] = \sprintf('%s.toolbox_mode must be "tags" or "explicit".', $path);
            } else {
                $toolboxMode = $parsedMode;
            }
        }

        $toolbox = [];
        if (\array_key_exists('toolbox', $step)) {
            $rawToolbox = $step['toolbox'];
            if (!\is_array($rawToolbox) || !array_is_list($rawToolbox)) {
                $problems[] = \sprintf('%s.toolbox must be an array of strings.', $path);
            } else {
                foreach ($rawToolbox as $index => $item) {
                    if (!\is_string($item) || '' === trim($item)) {
                        $problems[] = \sprintf('%s.toolbox[%d] must be a non-empty string.', $path, $index);
                    } else {
                        $toolbox[] = trim($item);
                    }
                }
            }
        }

        if (\count($problems) !== $problemCount) {
            return null;
        }

        return new StepSpec(
            trim((string) $title),
            trim((string) $brief),
            $toolboxMode,
            $toolbox,
            $level,
        );
    }
}
