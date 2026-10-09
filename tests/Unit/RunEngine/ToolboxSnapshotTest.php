<?php

declare(strict_types=1);

namespace App\Tests\Unit\RunEngine;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Entity\ToolDefinition;
use App\RunEngine\ToolboxSnapshot;
use App\Session\SessionTool;
use PHPUnit\Framework\TestCase;

/**
 * The frozen toolbox snapshot, round-tripped (SPEC §4.1, docs/design/
 * SESSION_TASKS.md §5): catalog tools and the harness's own session tools
 * ride one list, marked by `origin`; a snapshot written before the harness
 * tools existed reads as all-catalog; a harness entry whose tool no longer
 * exists is skipped rather than resurrected.
 */
final class ToolboxSnapshotTest extends TestCase
{
    public function testACatalogToolRoundTripsWithItsServerAndNoSecret(): void
    {
        $tool = $this->catalogTool();

        $snapshot = ToolboxSnapshot::fromDefinitions([$tool]);

        self::assertCount(1, $snapshot);
        $entry = $snapshot[0];
        self::assertSame('mcp', $entry['origin']);
        self::assertSame('weather-server', $entry['server']);
        self::assertSame('https://weather.example/mcp', $entry['serverUrl']);
        self::assertSame('get_weather', $entry['tool']);
        self::assertSame('WEATHER_KEY', $entry['credVar'], 'the env var NAME travels; the value never does');
        self::assertArrayNotHasKey('credential', $entry);

        $rebuilt = ToolboxSnapshot::toDefinitions($snapshot);
        self::assertCount(1, $rebuilt);
        self::assertInstanceOf(Tool::class, $rebuilt[0]);
        self::assertSame('get_weather', $rebuilt[0]->getName());
        self::assertSame($tool->getSchema(), $rebuilt[0]->getSchema());
        self::assertSame('https://weather.example/mcp', $rebuilt[0]->getServer()->getUrl());
    }

    public function testAHarnessToolRoundTripsWithNoServerFields(): void
    {
        $snapshot = ToolboxSnapshot::fromDefinitions([SessionTool::Note]);

        self::assertCount(1, $snapshot);
        $entry = $snapshot[0];
        self::assertSame('harness', $entry['origin']);
        self::assertSame('session_note', $entry['tool']);
        self::assertArrayNotHasKey('serverUrl', $entry, 'there is no server to call — the absence is the record');
        self::assertArrayNotHasKey('credVar', $entry);

        $rebuilt = ToolboxSnapshot::toDefinitions($snapshot);
        self::assertSame([SessionTool::Note], $rebuilt);
    }

    public function testBothOriginsRideOneListInOrder(): void
    {
        $snapshot = ToolboxSnapshot::fromDefinitions([$this->catalogTool(), SessionTool::Note, SessionTool::Objective]);

        self::assertSame(['mcp', 'harness', 'harness'], array_column($snapshot, 'origin'));

        $rebuilt = ToolboxSnapshot::toDefinitions($snapshot);
        self::assertCount(3, $rebuilt);
        self::assertInstanceOf(Tool::class, $rebuilt[0]);
        self::assertSame(SessionTool::Note, $rebuilt[1]);
        self::assertSame(SessionTool::Objective, $rebuilt[2]);
    }

    public function testASnapshotWithoutOriginReadsAsCatalog(): void
    {
        // Every snapshot written before the harness tools existed (and every
        // chat snapshot) carries no `origin`; the default is the record.
        $snapshot = [[
            'server' => 'legacy-server',
            'serverUrl' => 'https://legacy.example/mcp',
            'protocol' => 'mcp',
            'tool' => 'old_tool',
            'description' => 'from before',
            'schema' => ['type' => 'object'],
        ]];

        $rebuilt = ToolboxSnapshot::toDefinitions($snapshot);

        self::assertCount(1, $rebuilt);
        self::assertInstanceOf(Tool::class, $rebuilt[0]);
        self::assertSame('old_tool', $rebuilt[0]->getName());
    }

    public function testARetiredHarnessToolIsSkippedNotResurrected(): void
    {
        $snapshot = [[
            'origin' => 'harness',
            'tool' => 'session_retired',
            'description' => 'a tool that no longer exists',
            'schema' => ['type' => 'object'],
        ]];

        self::assertSame([], ToolboxSnapshot::toDefinitions($snapshot), 'the call to it will fail as not-in-toolbox — the honest outcome');
    }

    public function testToToolsReturnsOnlyCatalogTools(): void
    {
        $snapshot = ToolboxSnapshot::fromDefinitions([$this->catalogTool(), SessionTool::Note]);

        $tools = ToolboxSnapshot::toTools($snapshot);

        self::assertCount(1, $tools);
        self::assertSame('get_weather', $tools[0]->getName(), 'the MCP-only reader (chat) never surfaces a session tool');
    }

    public function testAnUnknownDefinitionIsRefusedLoudly(): void
    {
        $impostor = new class implements ToolDefinition {
            #[\Override]
            public function getName(): string
            {
                return 'impostor';
            }

            #[\Override]
            public function getDescription(): ?string
            {
                return null;
            }

            #[\Override]
            public function getSchema(): array
            {
                return [];
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown toolbox definition');

        ToolboxSnapshot::fromDefinitions([$impostor]);
    }

    private function catalogTool(): Tool
    {
        $server = new McpServer('weather-server', 'https://weather.example/mcp', ServerProtocol::Mcp, 'WEATHER_KEY');

        return new Tool($server, 'get_weather', 'Fetch the weather', [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ]);
    }
}
