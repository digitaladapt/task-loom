<?php

declare(strict_types=1);

namespace App\Tests\Unit\RunEngine;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\RunEngine\ToolExecutor;
use PHPUnit\Framework\TestCase;

/**
 * ToolExecutor::validate — the validate-before-dispatch gate (SPEC §5.1),
 * exercised against real JSON Schemas via opis (the same validator the
 * php-mcp server uses).
 */
final class ToolExecutorValidateTest extends TestCase
{
    private ToolExecutor $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->executor = new ToolExecutor();
    }

    public function testValidArgumentsPass(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => [
                'location' => ['type' => 'string'],
                'days' => ['type' => 'integer'],
            ],
            'required' => ['location'],
        ]);

        self::assertSame([], $this->executor->validate($tool, ['location' => 'Reykjavik', 'days' => 3]));
    }

    public function testMissingRequiredArgumentFails(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ]);

        $errors = $this->executor->validate($tool, []);

        self::assertNotSame([], $errors);
        self::assertStringContainsStringIgnoringCase('required', implode(' ', $errors));
    }

    public function testWrongTypeFails(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => ['days' => ['type' => 'integer']],
        ]);

        $errors = $this->executor->validate($tool, ['days' => 'three']);

        self::assertNotSame([], $errors);
    }

    public function testAdditionalPropertiesRejectedWhenSchemaSaysSo(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'additionalProperties' => false,
        ]);

        $errors = $this->executor->validate($tool, ['a' => 'x', 'b' => 'y']);

        self::assertNotSame([], $errors);
    }

    public function testEmptySchemaValidatesAnything(): void
    {
        $tool = $this->tool([]);

        self::assertSame([], $this->executor->validate($tool, ['anything' => 'goes']));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function tool(array $schema): Tool
    {
        $server = new McpServer('srv', 'https://x.example', ServerProtocol::Mcp);

        return new Tool($server, 'test_tool', 'test', $schema, []);
    }
}
