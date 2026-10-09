<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Entity\ToolDefinition;
use App\Repository\ToolRepository;
use App\Session\SessionTool;

/**
 * Resolves a task's toolbox declaration to the frozen tool set for a run
 * (SPEC §4.1): explicit names resolve exactly; tags resolve against the
 * discovered catalog (task.tags ∩ tool.tags). Resolution happens once, at
 * run start — no mid-run tool expansion.
 *
 * **A session's frozen toolbox is its operator-selected MCP tools PLUS the
 * harness's own session tools** (SPEC §4.1's harness-tool clause,
 * docs/design/SESSION_TASKS.md §5, build order step 3). The harness tools
 * are fixed too — the model cannot call them into existence, and cannot
 * write anything the tools do not express — but the *whole toolbox* is
 * frozen at slice start exactly as before. A session may resolve to zero
 * MCP tools (harness tools remain); an ordinary task may not, as before.
 * The harness names are reserved: a catalog tool carrying one is refused at
 * resolution rather than shadowed at dispatch.
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
     * @return list<ToolDefinition>
     *
     * @throws ToolboxResolutionException when the resolution yields no MCP tools for an
     *                                    ordinary task, or a catalog tool collides with a
     *                                    harness tool name
     */
    public function resolve(Task $task): array
    {
        $mcp = $this->resolveDeclaration(
            $task->getToolboxMode(),
            $task->getToolbox(),
            \sprintf('Task "%s"', $task->getTitle()),
            allowEmpty: TaskKind::Session === $task->getKind(),
        );

        if (TaskKind::Session !== $task->getKind()) {
            return $mcp;
        }

        // SPEC §4.1's harness-tool clause: a session's frozen toolbox is its
        // MCP tools plus the harness's own session tools. The reservation
        // check runs on what actually resolved — an explicit declaration and
        // a tag intersection get the same treatment, so a catalog tool
        // named like a harness tool can never shadow it at dispatch, by any
        // route.
        foreach ($mcp as $tool) {
            if (\in_array($tool->getName(), SessionTool::names(), true)) {
                throw new ToolboxResolutionException(\sprintf('Task "%s" resolves tool "%s", but that name is reserved for the harness\'s own session tools.', $task->getTitle(), $tool->getName()));
            }
        }

        return [...$mcp, ...SessionTool::cases()];
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
     * Resolve an ad-hoc declaration (SPEC §15, `CHAT_TOOLS.md`): a chat
     * exchange's toolbox, chosen per message.
     *
     * Shares every rule with a task's resolution — the same explicit lookup,
     * the same tag intersection, the same canonical order — and differs in
     * exactly one: **an empty result is not an exception.** For a task an
     * empty toolbox is a dispatch failure, because a task exists to do
     * something. For a conversation it is the ordinary case (most chats want
     * no tools) and, when it comes from tags, often just a tag nobody has
     * applied yet. The caller decides what to say about it; the resolver does
     * not invent a failure.
     *
     * @param list<string> $declared
     *
     * @return list<Tool>
     */
    public function resolveChat(ToolboxMode $mode, array $declared): array
    {
        if (ToolboxMode::Explicit === $mode) {
            return array_values(array_filter(array_map(
                fn (string $name): ?Tool => $this->tools->findOneBy(['name' => $name]),
                $declared,
            )));
        }

        return $this->resolveByTags($declared);
    }

    /**
     * The catalog as a picker offers it: enabled tools on enabled servers, in
     * the canonical order.
     *
     * @return list<Tool>
     */
    public function catalog(): array
    {
        return array_values(array_filter(
            $this->tools->findAllOrdered(),
            static fn (Tool $tool): bool => $tool->getServer()->isEnabled(),
        ));
    }

    /**
     * @param list<string> $declared
     *
     * @return list<Tool>
     *
     * @throws ToolboxResolutionException when the resolution yields no tools
     */
    private function resolveDeclaration(ToolboxMode $mode, array $declared, string $subject, bool $allowEmpty = false): array
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

        if ([] === $resolved && !$allowEmpty) {
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
        // over ALL tools, then intersect with the declared tags. The
        // canonical order (server, then tool) is preserved into the frozen
        // snapshot, so a run's toolbox reads the same way the catalog page
        // and the editor's picker present it.
        $all = $this->tools->findAllOrdered();

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
