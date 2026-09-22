<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Toolbox\OpenApiServerReader;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the "$ref chain too deep" crash on the context-shuttle
 * OpenAPI spec: the reader's depth guard counted array nesting depth, not
 * $ref hops, so deeply nested response schemas (with zero refs) blew the
 * limit. The guard now counts only ref hops.
 */
final class OpenApiServerReaderDeepNestingTest extends TestCase
{
    private OpenApiServerReader $reader; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->reader = new OpenApiServerReader();
    }

    public function testDeeplyNestedSchemasWithoutRefsDoNotTripTheGuard(): void
    {
        // Model the context-shuttle /tools path: a response schema nested
        // 11+ levels deep (responses → 200 → content → json → schema →
        // properties → tools → items → properties → ...) with NO refs.
        $nested = ['type' => 'string'];
        for ($i = 0; $i < 20; ++$i) {
            $nested = ['type' => 'object', 'properties' => ['p' => $nested]];
        }

        $spec = [
            'paths' => [
                '/deep' => ['get' => [
                    'operationId' => 'list_deep',
                    'parameters' => [],
                    'responses' => ['200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => $nested]],
                    ]],
                ]],
            ],
        ];

        $tools = $this->reader->specToTools($spec);

        self::assertCount(1, $tools);
        self::assertSame('list_deep', $tools[0]->name);
    }

    public function testSelfReferentialRefChainStillRejected(): void
    {
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
}
