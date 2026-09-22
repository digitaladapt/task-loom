<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for phantom schema drift: freshly built schemas use
 * stdClass for empty JSON objects while Doctrine's JSON round-trip decodes
 * them to empty arrays, so strict !== reported drift on every sync of a
 * server whose tools have parameterless schemas (context-shuttle).
 */
final class ToolSchemaDriftTest extends TestCase
{
    public function testEmptyObjectStdClassAndEmptyArrayAreNotDrift(): void
    {
        $server = new McpServer('weather', 'http://example.invalid/mcp', ServerProtocol::Mcp);

        // Fresh discovery: empty properties as stdClass (JSON object {}).
        $fresh = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $tool = new Tool($server, 'echo', 'Echo', $fresh, ['weather']);
        $tool->markDiscovered(new \DateTimeImmutable('2026-01-01 00:00:00'));

        // Simulate Doctrine's JSON round-trip: {} decodes to [].
        $roundTripped = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];

        self::assertSame([], $tool->mergeDiscovered('Echo', $roundTripped), 'no drift expected');
    }

    public function testRealSchemaChangeIsStillDrift(): void
    {
        $server = new McpServer('weather', 'http://example.invalid/mcp', ServerProtocol::Mcp);

        $fresh = ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]];
        $tool = new Tool($server, 'echo', 'Echo', $fresh, ['weather']);
        $tool->markDiscovered(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $changed = ['type' => 'object', 'properties' => ['message' => ['type' => 'integer']]];
        $drift = $tool->mergeDiscovered('Echo', $changed);

        self::assertSame(['schema'], $drift);
    }
}
