<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Toolbox\SchemaNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * SchemaNormalizer repairs the JSON-object members Doctrine's JSON
 * round-trip decodes to arrays — the reason parameterless tools used to
 * fail validation ("properties must be an object").
 */
final class SchemaNormalizerTest extends TestCase
{
    public function testEmptyPropertiesBecomesAnObject(): void
    {
        $normalized = SchemaNormalizer::normalize([
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ]);

        self::assertInstanceOf(\stdClass::class, $normalized['properties']);
        self::assertSame('object', $normalized['type']);
        self::assertFalse($normalized['additionalProperties']);
    }

    public function testNonEmptyPropertiesIsLeftAlone(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
            'required' => ['message'],
        ];

        $normalized = SchemaNormalizer::normalize($schema);

        self::assertSame($schema, $normalized);
    }

    public function testEmptyArrayKeywordsOtherThanObjectMapsAreLeftAlone(): void
    {
        // `required: []`, `enum: []`, `allOf: []` are legitimately arrays;
        // only the object-map keywords may be repaired.
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'required' => [],
            'allOf' => [],
        ];

        $normalized = SchemaNormalizer::normalize($schema);

        self::assertSame([], $normalized['required']);
        self::assertSame([], $normalized['allOf']);
    }

    public function testNestedObjectMembersAreRepairedRecursively(): void
    {
        $normalized = SchemaNormalizer::normalize([
            'type' => 'object',
            'properties' => [
                'inner' => ['type' => 'object', 'properties' => []],
            ],
            '$defs' => [],
        ]);

        self::assertInstanceOf(\stdClass::class, $normalized['$defs']);
        self::assertIsArray($normalized['properties']);
        $inner = $normalized['properties']['inner'];
        self::assertIsArray($inner);
        self::assertInstanceOf(\stdClass::class, $inner['properties']);
    }

    public function testExistingObjectsPassThroughUntouched(): void
    {
        $empty = new \stdClass();
        $normalized = SchemaNormalizer::normalize(['type' => 'object', 'properties' => $empty]);

        self::assertSame($empty, $normalized['properties'], 'an existing object is not rebuilt');
    }
}
