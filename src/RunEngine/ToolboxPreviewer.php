<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\ToolRepository;
use App\Session\SessionTool;

/**
 * A non-throwing resolution pass for the admin UI (SPEC §8): what would
 * this toolbox declaration resolve to right now, and if nothing, why?
 *
 * This exists because of a live incident: an LLM-authored task declared
 * tags ('core') that no tool in the catalog carries; the run then failed
 * at dispatch with an empty toolbox. The approval queue must surface that
 * before a human enables the task — enable is the control point, so
 * enable needs the diagnostic.
 *
 * Under the step model (SPEC §13) the same incident class applies per
 * step: each child run resolves its own toolbox at child-run start, so
 * the task detail page previews every step's declaration too
 * (previewStep()).
 *
 * resolve() stays the authority at run time (fails loudly, SPEC §4.1);
 * preview() / previewStep() answer "what would resolve() say, without
 * throwing".
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
        $session = TaskKind::Session === $task->getKind();
        $preview = $this->previewDeclaration($task->getToolboxMode(), $task->getToolbox(), 'task', session: $session);

        if (!$session) {
            return $preview;
        }

        // SPEC §4.1's harness-tool clause, mirrored: the resolver adds the
        // harness's own session tools on top of the declared half, and
        // refuses a catalog tool carrying a reserved name. The preview shows
        // both facts — "what would resolve() say, without throwing" — so a
        // session page never reads as though the harness tools were absent,
        // and an empty declared half is not misreported as a dispatch
        // failure it is not.
        $problems = $preview->problems;
        foreach ($preview->resolved as $tool) {
            if (\in_array($tool->getName(), SessionTool::names(), true)) {
                $problems[] = \sprintf(
                    'Tool "%s" is reserved for the harness\'s own session tools — a slice refuses this name at start.',
                    $tool->getName(),
                );
            }
        }

        return new ToolboxPreview($preview->resolved, $problems, SessionTool::names());
    }

    /**
     * The same pass for one step's toolbox declaration (SPEC §13.1: a
     * step carries its own toolbox, resolved at its child-run start).
     */
    public function previewStep(Step $step): ToolboxPreview
    {
        return $this->previewDeclaration($step->getToolboxMode(), $step->getToolbox(), 'step');
    }

    /**
     * @param list<string> $declared
     */
    private function previewDeclaration(ToolboxMode $mode, array $declared, string $subject, bool $session = false): ToolboxPreview
    {
        $resolved = [];
        $problems = [];

        if (ToolboxMode::Explicit === $mode) {
            foreach ($declared as $name) {
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
        // intersected with the declared tags.
        if ([] === $declared) {
            // A session's declared half being empty is legal by design (§5:
            // the harness tools remain, and a session may resolve to zero MCP
            // tools) — so the message must not claim the toolbox would be
            // empty, which is only true of an ordinary task.
            $problems[] = $session
                ? \sprintf('The %s declares no tags — no catalog tools will be added; the harness\'s own session tools are still included.', $subject)
                : \sprintf('The %s declares no tags — the toolbox would be empty.', $subject);

            return new ToolboxPreview($resolved, $problems);
        }

        // Canonical order (server, then tool): the preview must present the
        // same tools in the same order as the editor's picker and the run's
        // frozen toolbox, or the operator sees one list and gets another.
        $all = $this->tools->findAllOrdered();

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
                    'No tool carries the tag "%s" — edit tool tags in the catalog, or fix the %s.',
                    $tag,
                    $subject,
                );
            }
        }

        return new ToolboxPreview($resolved, $problems);
    }
}
