<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Task;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\ToolRepository;

/**
 * Resolves a task's toolbox declaration to the frozen tool set for a run
 * (SPEC §4.1): explicit names resolve exactly; tags resolve against the
 * discovered catalog (task.tags ∩ tool.tags). Resolution happens once, at
 * run start — no mid-run tool expansion.
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
        $resolved = [];

        if (ToolboxMode::Explicit === $task->getToolboxMode()) {
            foreach ($task->getToolbox() as $name) {
                $tool = $this->tools->findOneBy(['name' => $name]);
                if (null === $tool) {
                    throw new \LogicException(\sprintf('Task "%s" declares tool "%s", which is not in the catalog.', $task->getTitle(), $name));
                }
                $resolved[] = $tool;
            }
        } else {
            $resolved = $this->resolveByTags($task);
        }

        if ([] === $resolved) {
            throw new \LogicException(\sprintf('Task "%s" resolves to an empty toolbox.', $task->getTitle()));
        }

        return $resolved;
    }

    /**
     * Tags resolve against the discovered catalog (SPEC §4.1) — every
     * enabled tool from every enabled server, intersected with the task's
     * declared tags.
     *
     * @return list<Tool>
     */
    private function resolveByTags(Task $task): array
    {
        $declared = $task->getToolbox();

        if ([] === $declared) {
            return [];
        }

        // The catalog: enabled tools on enabled servers. We must resolve
        // over ALL tools, then intersect with the task's tags.
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
