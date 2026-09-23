<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;

/**
 * The frozen toolbox (SPEC §4.1) as persisted on the run, and as rebuilt by
 * a fresh worker mid-run (SPEC §6): the snapshot must carry everything the
 * engine needs at turn time — prompt compilation, OpenAI tool descriptors,
 * and tool dispatch — WITHOUT consulting the catalog, which may have
 * changed since the run started. The run's constitution does not move.
 *
 * Deliberately stores no secret: `cred_var` (an env var NAME) exists on the
 * catalog entity but nothing on the execution path reads it in v1.
 *
 * Shape per entry: {server, serverUrl, protocol, tool, description, schema}.
 */
final readonly class ToolboxSnapshot
{
    /**
     * @param list<Tool> $tools
     *
     * @return list<array<string, mixed>>
     */
    public static function fromTools(array $tools): array
    {
        return array_map(
            static fn (Tool $tool): array => [
                'server' => $tool->getServer()->getName(),
                'serverUrl' => $tool->getServer()->getUrl(),
                'protocol' => $tool->getServer()->getProtocol()->value,
                'tool' => $tool->getName(),
                'description' => $tool->getDescription(),
                'schema' => $tool->getSchema(),
            ],
            $tools,
        );
    }

    /**
     * Rebuild the tool set from a stored snapshot. The entities are
     * transient — constructed for this turn, never persisted, never merged
     * with the catalog. Only the fields the prompt compiler and the tool
     * executor read are restored.
     *
     * @param list<array<string, mixed>> $snapshot
     *
     * @return list<Tool>
     */
    public static function toTools(array $snapshot): array
    {
        $tools = [];
        foreach ($snapshot as $entry) {
            $schema = $entry['schema'] ?? [];
            $server = new McpServer(
                (string) ($entry['server'] ?? ''),
                (string) ($entry['serverUrl'] ?? ''),
                ServerProtocol::tryFrom((string) ($entry['protocol'] ?? '')) ?? ServerProtocol::Mcp,
            );

            $tools[] = new Tool(
                $server,
                (string) ($entry['tool'] ?? ''),
                isset($entry['description']) ? (string) $entry['description'] : null,
                \is_array($schema) ? $schema : [],
            );
        }

        return $tools;
    }
}
