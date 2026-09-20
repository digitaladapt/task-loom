<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Toolbox\OpenApiServerReader;
use PHPUnit\Framework\TestCase;

final class OpenApiServerReaderSpecToToolsTest extends TestCase
{
    private OpenApiServerReader $reader; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->reader = new OpenApiServerReader();
    }

    /**
     * @return array<string, mixed>
     */
    private function weatherSpec(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/weather-openapi.json'), true);
    }

    public function testConvertsPathsToTools(): void
    {
        $tools = $this->reader->specToTools($this->weatherSpec());

        self::assertCount(2, $tools);

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        // operationId wins as the tool name
        self::assertArrayHasKey('get_current_weather', $byName);
        // fallback: method + path slugified
        self::assertArrayHasKey('get_v1_forecast_city', $byName);
    }

    public function testParametersBecomeJsonSchema(): void
    {
        $tools = $this->reader->specToTools($this->weatherSpec());
        $current = null;
        foreach ($tools as $tool) {
            if ('get_current_weather' === $tool->name) {
                $current = $tool;
            }
        }

        self::assertNotNull($current);
        self::assertSame(
            ['city' => ['type' => 'string'], 'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']]],
            (array) $current->schema['properties'],
        );
        self::assertSame(['city'], $current->schema['required']);
        self::assertFalse($current->schema['additionalProperties']);
    }

    public function testResolvesInternalRefsInParameters(): void
    {
        $tools = $this->reader->specToTools($this->weatherSpec());
        $forecast = null;
        foreach ($tools as $tool) {
            if ('get_v1_forecast_city' === $tool->name) {
                $forecast = $tool;
            }
        }

        self::assertNotNull($forecast);
        // The $ref'd "days" parameter must be inlined as a property with its
        // constraints intact.
        $props = (array) $forecast->schema['properties'];
        self::assertArrayHasKey('days', $props);
        self::assertSame('integer', $props['days']['type']);
        self::assertSame(1, $props['days']['minimum']);
        self::assertSame(7, $props['days']['maximum']);
        self::assertNotContains('$ref', array_keys($props['days']));
    }

    public function testDescriptionCarriesMethodAndPath(): void
    {
        $tools = $this->reader->specToTools($this->weatherSpec());
        $current = null;
        foreach ($tools as $tool) {
            if ('get_current_weather' === $tool->name) {
                $current = $tool;
            }
        }

        self::assertNotNull($current);
        self::assertStringStartsWith('[GET /v1/current]', $current->description);
    }

    public function testRejectsSpecWithoutPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paths');

        $this->reader->specToTools(['openapi' => '3.0.0']);
    }

    public function testRefDepthGuard(): void
    {
        // A self-referential $ref: nodes resolve to each other forever.
        $spec = [
            'paths' => [
                '/a' => ['get' => [
                    'parameters' => [['$ref' => '#/components/parameters/P']],
                ]],
            ],
            'components' => ['parameters' => [
                'P' => ['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => '#/components/parameters/P']],
            ]],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too deep');

        $this->reader->specToTools($spec);
    }

    public function testSanitizesOperationIdAndDeduplicates(): void
    {
        $spec = [
            'paths' => [
                '/a' => ['get' => ['operationId' => 'weird id.with spaces!', 'parameters' => []]],
                '/b' => ['get' => ['operationId' => 'weird id.with spaces!', 'parameters' => []]],
                '/c' => ['post' => ['parameters' => []]],
            ],
        ];

        $tools = $this->reader->specToTools($spec);

        $names = array_map(static fn ($t) => $t->name, $tools);
        self::assertSame(['weird_id_with_spaces', 'weird_id_with_spaces_1', 'post_c'], $names);
    }
}
