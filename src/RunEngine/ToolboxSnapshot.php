<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Entity\ToolDefinition;
use App\Session\SessionDeclareTool;
use App\Session\SessionTool;

/**
 * The frozen toolbox (SPEC §4.1) as persisted on the run, and as rebuilt by
 * a fresh worker mid-run (SPEC §6): the snapshot must carry everything the
 * engine needs at turn time — prompt compilation, OpenAI tool descriptors,
 * and tool dispatch — WITHOUT consulting the catalog, which may have
 * changed since the run started. The run's constitution does not move.
 *
 * Every entry carries `origin`, and there are exactly two (SPEC §4.1's
 * harness-tool clause, docs/design/SESSION_TASKS.md §5, build order step 3):
 *
 * - **`mcp`** — a catalog tool: {server, serverUrl, protocol, credVar, tool,
 *   description, schema}. Deliberately stores no secret: `credVar` is the
 *   NAME of an environment variable, never its value — the value is read
 *   from the process environment at call time by CredentialResolver and
 *   never crosses into the snapshot, a log, or the ledger.
 * - **`harness`** — a tool the harness implements itself, the session
 *   tools: {origin, tool, description, schema}. There is no server to call:
 *   no serverUrl, no protocol, no credential — the absence is the record,
 *   and dispatch routes these in-process. They ride the snapshot so a
 *   session's whole toolbox stays frozen, in one list, exactly as before.
 *
 * Absent `origin` reads as `mcp`: every snapshot written before the harness
 * tools existed (and every chat snapshot — a conversation never carries
 * harness entries) keeps behaving unchanged.
 */
final readonly class ToolboxSnapshot
{
    private const string ORIGIN_MCP = 'mcp';
    private const string ORIGIN_HARNESS = 'harness';

    /**
     * @param list<ToolDefinition> $definitions
     *
     * @return list<array<string, mixed>>
     */
    public static function fromDefinitions(array $definitions): array
    {
        $entries = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof Tool) {
                $entries[] = [
                    'origin' => self::ORIGIN_MCP,
                    'server' => $definition->getServer()->getName(),
                    'serverUrl' => $definition->getServer()->getUrl(),
                    'protocol' => $definition->getServer()->getProtocol()->value,
                    'credVar' => $definition->getServer()->getCredVar(),
                    'tool' => $definition->getName(),
                    'description' => $definition->getDescription(),
                    'schema' => $definition->getSchema(),
                ];

                continue;
            }

            if ($definition instanceof SessionTool) {
                $entries[] = [
                    'origin' => self::ORIGIN_HARNESS,
                    'tool' => $definition->getName(),
                    'description' => $definition->getDescription(),
                    'schema' => $definition->getSchema(),
                ];

                continue;
            }

            if ($definition instanceof SessionDeclareTool) {
                // The FILTERED declaration (docs/design/SESSION_ENDINGS.md
                // §3): its schema and description are the session's ending
                // policy as the wire carried it, so they are stored
                // verbatim — the rebuild below reads them back rather than
                // re-deriving from the enum case.
                $entries[] = [
                    'origin' => self::ORIGIN_HARNESS,
                    'tool' => $definition->getName(),
                    'description' => $definition->getDescription(),
                    'schema' => $definition->getSchema(),
                ];

                continue;
            }

            throw new \LogicException(\sprintf('Unknown toolbox definition "%s" — the toolbox is catalog tools plus the harness\'s own session tools, nothing else.', $definition::class));
        }

        return $entries;
    }

    /**
     * Rebuild the toolbox from a stored snapshot: MCP tools as transient
     * {@see Tool} entities (constructed for this turn, never persisted,
     * never merged with the catalog), harness tools as their enum cases —
     * EXCEPT `session_declare`, which rebuilds from the stored entry itself
     * ({@see SessionDeclareTool::fromSnapshot()}): a session's endings are
     * frozen in the snapshot, and re-deriving them from the enum case here
     * would silently re-widen the vocabulary on the first rehydrated turn
     * — exactly the turn a fresh worker runs.
     *
     * A harness entry whose name no longer names an enum case — a harness
     * tool retired after this snapshot was frozen — is skipped: the model's
     * call to it then fails as not-in-toolbox, which is the honest outcome
     * for a capability that no longer exists.
     *
     * @param list<array<string, mixed>> $snapshot
     *
     * @return list<ToolDefinition>
     */
    public static function toDefinitions(array $snapshot): array
    {
        $definitions = [];

        foreach ($snapshot as $entry) {
            if (self::ORIGIN_HARNESS === ($entry['origin'] ?? self::ORIGIN_MCP)) {
                $name = (string) ($entry['tool'] ?? '');

                if (SessionDeclareTool::NAME === $name) {
                    $definitions[] = SessionDeclareTool::fromSnapshot(
                        isset($entry['description']) ? (string) $entry['description'] : '',
                        \is_array($entry['schema'] ?? null) ? $entry['schema'] : [],
                    );

                    continue;
                }

                $tool = SessionTool::tryFrom($name);
                if (null !== $tool) {
                    $definitions[] = $tool;
                }

                continue;
            }

            $schema = $entry['schema'] ?? [];
            $server = new McpServer(
                (string) ($entry['server'] ?? ''),
                (string) ($entry['serverUrl'] ?? ''),
                ServerProtocol::tryFrom((string) ($entry['protocol'] ?? '')) ?? ServerProtocol::Mcp,
                isset($entry['credVar']) && \is_string($entry['credVar']) && '' !== $entry['credVar']
                    ? $entry['credVar']
                    : null,
            );

            $definitions[] = new Tool(
                $server,
                (string) ($entry['tool'] ?? ''),
                isset($entry['description']) ? (string) $entry['description'] : null,
                \is_array($schema) ? $schema : [],
            );
        }

        return $definitions;
    }

    /**
     * The MCP tools from a stored snapshot — the reader chat uses (a
     * conversation's toolbox never carries harness entries; any that somehow
     * appeared are skipped, because a chat cannot call session tools).
     *
     * @param list<array<string, mixed>> $snapshot
     *
     * @return list<Tool>
     */
    public static function toTools(array $snapshot): array
    {
        $tools = [];
        foreach (self::toDefinitions($snapshot) as $definition) {
            if ($definition instanceof Tool) {
                $tools[] = $definition;
            }
        }

        return $tools;
    }
}
