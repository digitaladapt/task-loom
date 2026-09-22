<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Tool;
use App\Repository\ToolRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The tool-catalog admin surface (SPEC §8): tag management on discovered
 * tools. Tags are the only human-curated field on a discovered tool —
 * everything else is server-owned (the synchronizer's merge, SPEC §7).
 *
 * Setting tags pins nothing; pinning is a separate, deliberate act
 * (Tool::pin) for hand-curated content. Tags set here survive syncs
 * because sync never touches the tags column (only description/schema
 * drift).
 */
final class ToolAdminService
{
    public function __construct(
        private readonly ToolRepository $tools,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Tool>
     */
    public function listTools(): array
    {
        return $this->tools->findBy([], ['name' => 'ASC']);
    }

    public function findTool(int $toolId): ?Tool
    {
        return $this->tools->find($toolId);
    }

    /**
     * Every distinct tag in use, sorted — the tag picker's source of truth.
     *
     * @return list<string>
     */
    public function listKnownTags(): array
    {
        $tags = [];
        foreach ($this->tools->findAll() as $tool) {
            foreach ($tool->getTags() as $tag) {
                $tags[$tag] = true;
            }
        }

        $tags = \array_keys($tags);
        \sort($tags);

        return $tags;
    }

    /**
     * Replace one tool's tags (SPEC §4.1: task.tags ∩ tool.tags → toolbox).
     *
     * @param list<string> $tags
     *
     * @throws ToolLifecycleException when the tool does not exist
     */
    public function setToolTags(int $toolId, array $tags): Tool
    {
        $tool = $this->tools->find($toolId);
        if (!$tool instanceof Tool) {
            throw new ToolLifecycleException(\sprintf('No tool with id %d.', $toolId));
        }

        $tags = $this->normalizeTags($tags);

        $tool->setTags($tags);
        $this->em->flush();

        return $tool;
    }

    /**
     * Normalize a tag list: trim, drop empties and duplicates, sort.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = \trim($tag);
            if ('' === $tag) {
                continue;
            }
            $normalized[$tag] = true;
        }

        $normalized = \array_keys($normalized);
        \sort($normalized);

        return $normalized;
    }
}
