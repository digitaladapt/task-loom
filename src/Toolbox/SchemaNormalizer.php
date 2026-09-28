<?php

declare(strict_types=1);

namespace App\Toolbox;

/**
 * Repairs JSON Schema documents for consumers that need the JSON-object /
 * JSON-array distinction PHP arrays cannot express.
 *
 * The bug this exists for: a tool whose schema declares no arguments has
 * an empty `properties` member — a JSON *object* (`{}`) on the wire. It is
 * persisted in a Doctrine JSON column, whose round-trip decodes `{}` to
 * `[]`, and a JSON array is not a JSON object to a strict schema parser
 * (opis rejects it outright with "properties must be an object"). Without
 * normalization, every parameterless tool — the most common shape there
 * is — either fails validation or throws out of the dispatch path.
 *
 * The repair is keyword-directed, not heuristic: only members that JSON
 * Schema defines as objects (a map of names to subschemas) are converted,
 * and only when they are empty. Empty *arrays* that are legitimately
 * arrays (`required: []`, `enum: []`, `allOf: []`) are left alone. The
 * walk is recursive, so nested subschemas are repaired wherever they
 * appear.
 *
 * Note this is a consumption-side repair, deliberately: the stored shape
 * stays exactly as discovered (the drift check in Tool::mergeDiscovered
 * already treats `{}` and `[]` as equivalent), and both consumers — the
 * argument validator and the OpenAI tool descriptors — normalize on read.
 */
final class SchemaNormalizer
{
    /**
     * JSON Schema keywords whose value is a JSON object: a map of names to
     * subschemas (or, for `dependencies`, to subschemas or string arrays —
     * an empty map is still an object).
     */
    private const OBJECT_KEYWORDS = [
        'properties',
        'patternProperties',
        '$defs',
        'definitions',
        'dependentSchemas',
        'dependencies',
    ];

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = self::walk($schema);

        return $normalized;
    }

    private static function walk(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return $value;
        }

        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array($key, self::OBJECT_KEYWORDS, true) && [] === $item) {
                $result[$key] = new \stdClass();

                continue;
            }

            $result[$key] = self::walk($item);
        }

        return $result;
    }
}
