<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\ToolRepository;

/**
 * Resolves a task's toolbox declaration to the frozen tool set for a run
 * (SPEC §4.1): explicit names resolve exactly; tags resolve against the
 * discovered catalog (task.tags ∩ tool.tags). Resolution happens once, at
 * run start — no mid-run tool expansion.
 *
 * resolveStep() is the same resolution for a step's declaration: under
 * run-per-step a step's toolbox IS its child run's toolbox (SPEC §13.1).
 */
final readonly class ToolboxResolver
{
    public function __construct(
        private ToolRepository $tools,
    ) {
    }

    /**
     * @return list<Tool>
     *
     * @throws \LogicException when the resolution yields no tools (a run with an
     *                         empty toolbox cannot do anything useful; better to
     *                         fail loudly at dispatch)
     */
    public function resolve(Task $task): array
    {
        return $this->resolveDeclaration(
            $task->getToolboxMode(),
            $task->getToolbox(),
            \sprintf('Task "%s"', $task->getTitle()),
        );
    }

    /**
     * The same resolution for one step's declaration (SPEC §13.1: a step
     * carries its own toolbox, resolved once at its child-run start).
     *
     * @return list<Tool>
     *
     * @throws \LogicException when the resolution yields no tools
     */
    public function resolveStep(Step $step): array
    {
        return $this->resolveDeclaration(
            $step->getToolboxMode(),
            $step->getToolbox(),
            \sprintf('Step "%s"', $step->getTitle()),
        );
    }

    /**
     * @param list<string> $declared
     *
     * @return list<Tool>
     *
     * @throws ToolboxResolutionException when the resolution yields no tools
     */
    private function resolveDeclaration(ToolboxMode $mode, array $declared, string $subject): array
    {
        $resolved = [];

        if (ToolboxMode::Explicit === $mode) {
            foreach ($declared as $name) {
                $tool = $this->tools->findOneBy(['name' => $name]);
                if (null === $tool) {
                    throw new ToolboxResolutionException(\sprintf('%s declares tool "%s", which is not in the catalog.', $subject, $name));
                }
                $resolved[] = $tool;
            }
        } else {
            $resolved = $this->resolveByTags($declared);
        }

        if ([] === $resolved) {
            throw new ToolboxResolutionException(\sprintf('%s resolves to an empty toolbox.', $subject));
        }

        return $resolved;
    }

    /**
     * Tags resolve against the discovered catalog (SPEC §4.1) — every
     * enabled tool from every enabled server, intersected with the declared
     * tags.
     *
     * @param list<string> $declared
     *
     * @return list<Tool>
     */
    private function resolveByTags(array $declared): array
    {
        if ([] === $declared) {
            return [];
        }

        // The catalog: enabled tools on enabled servers. We must resolve
        // over ALL tools, then intersect with the declared tags.
        $all = $this->tools->findBy([], ['name' => 'ASC']);

        $resolved = [];
        foreach ($all as $tool) {
            if (!$tool->getServer()->isEnabled()) {
                continue;
            }

            if ([] !== array_intersect($declared, $tool->getTags())) {
                $resolved[] = $tool;
            }
        }

        return $resolved;
    }
}
