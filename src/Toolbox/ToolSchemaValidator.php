<?php

declare(strict_types=1);

namespace App\Toolbox;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\ParseException as SchemaParseException;
use Opis\JsonSchema\Validator;

/**
 * Validate one call's arguments against one JSON Schema (SPEC §5.1,
 * validate-before-dispatch).
 *
 * Extracted from `ToolExecutor::validate()` when the harness's own session
 * tools arrived (docs/design/SESSION_TASKS.md §5, build order step 3): those
 * calls never cross the wire, but they are validated against their schema
 * exactly as an MCP call is, and "how a call is checked" must be one story —
 * two implementations is how the same malformed call gets two different
 * verdicts.
 *
 * The store keeps `schema` as discovered; the repairs JSON needs (empty
 * `properties` and friends decoded to arrays by Doctrine's JSON round-trip)
 * are applied here, on read, through {@see SchemaNormalizer} — same split as
 * the executor and the prompt compiler.
 */
final class ToolSchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     *
     * @return list<string> validation errors; empty = valid
     */
    public static function validate(array $schema, array $arguments): array
    {
        if ([] === $schema) {
            return []; // no schema recorded — nothing to validate against
        }

        $validator = new Validator();

        try {
            // SchemaNormalizer repairs the JSON-object members (empty
            // `properties` and friends) that Doctrine's JSON round-trip
            // decodes to arrays — a parameterless tool must validate, not
            // fail.
            $result = $validator->validate(self::toObject($arguments), self::toObject(SchemaNormalizer::normalize($schema)));
        } catch (SchemaParseException $e) {
            // A schema the validator cannot parse even after normalization
            // is corrupt data, not an invalid call — and it must never wedge
            // the run by escaping the engine's classify-and-record
            // discipline. Reject the call loudly as invalid_arguments so the
            // run records a diagnosis instead of dying on delivery.
            return [\sprintf('the tool\'s recorded schema is not a valid JSON Schema (%s); refusing to dispatch', $e->getMessage())];
        }

        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        $error = $result->error();
        if (null !== $error) {
            foreach (self::flattenValidationError($error) as $sub) {
                $errors[] = $sub;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function flattenValidationError(ValidationError $error): array
    {
        $errors = [$error->message()];

        foreach ($error->subErrors() as $sub) {
            foreach (self::flattenValidationError($sub) as $msg) {
                $errors[] = $msg;
            }
        }

        return $errors;
    }

    /**
     * Convert an associative array to stdClass for opis validation
     * (opis expects objects at the document root).
     *
     * @param array<string, mixed> $data
     */
    private static function toObject(array $data): \stdClass
    {
        return json_decode((string) json_encode($data)) ?: new \stdClass();
    }
}
