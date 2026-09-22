<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Task;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\ToolRepository;

/**
 * A non-throwing resolution pass for the admin UI (SPEC §8): what would
 * this task's toolbox resolve to right now, and if nothing, why?
 *
 * This exists because of a live incident: an LLM-authored task declared
 * tags ('core') that no tool in the catalog carries; the run then failed
 * at dispatch with an empty toolbox. The approval queue must surface that
 * before a human enables the task — enable is the control point, so
 * enable needs the diagnostic.
 *
 * resolve() stays the authority at run time (fails loudly, SPEC §4.1);
 * preview() answers "what would resolve() say, without throwing".
 */
final readonly class ToolboxPreviewer
{
    public function __construct(
        private ToolRepository $tools,
    ) {
    }

    /**
     * @return ToolboxPreview with resolved tools (possibly empty) and
     *                        diagnostics explaining any empty/missing pieces
     */
    public function preview(Task $task): ToolboxPreview
    {
        $resolved = [];
        $problems = [];

        if (ToolboxMode::Explicit === $task->getToolboxMode()) {
            foreach ($task->getToolbox() as $name) {
                $tool = $this->tools->findOneBy(['name' => $name]);

                if (null === $tool) {
                    $problems[] = \sprintf(
                        'Tool "%s" is not in the catalog — sync the servers or fix the list.',
                        $name,
                    );

                    continue;
                }

                if (!$tool->getServer()->isEnabled()) {
                    $problems[] = \sprintf(
                        'Tool "%s" exists but its server "%s" is disabled.',
                        $name,
                        $tool->getServer()->getName(),
                    );

                    continue;
                }

                $resolved[] = $tool;
            }

            return new ToolboxPreview($resolved, $problems);
        }

        // Tags mode: every enabled tool on every enabled server,
        // intersected with the task's declared tags.
        $declared = $task->getToolbox();

        if ([] === $declared) {
            $problems[] = 'The task declares no tags — the toolbox would be empty.';

            return new ToolboxPreview($resolved, $problems);
        }

        $all = $this->tools->findBy([], ['name' => 'ASC']);

        $matchedByTag = [];
        foreach ($all as $tool) {
            if (!$tool->getServer()->isEnabled()) {
                continue;
            }

            foreach ($declared as $tag) {
                if (\in_array($tag, $tool->getTags(), true)) {
                    $resolved[] = $tool;
                    $matchedByTag[$tag] = true;

                    continue 2;
                }
            }
        }

        foreach ($declared as $tag) {
            if (!isset($matchedByTag[$tag])) {
                $problems[] = \sprintf(
                    'No tool carries the tag "%s" — edit tool tags in the catalog, or fix the task.',
                    $tag,
                );
            }
        }

        return new ToolboxPreview($resolved, $problems);
    }
}
