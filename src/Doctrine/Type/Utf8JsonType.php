<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Context\Utf8;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\SerializationFailed;
use Doctrine\DBAL\Types\JsonType;

/**
 * The `json` type, scrubbed on write.
 *
 * Doctrine's JsonType encodes with JSON_THROW_ON_ERROR, so a single malformed
 * byte anywhere in the value aborts the whole write with
 * `SerializationFailed: Could not convert PHP type "array" to "json" … Malformed
 * UTF-8 characters, possibly incorrectly encoded`. That message names the
 * container, not the culprit, arrives mid-reply, and leaves a person with
 * nothing to act on.
 *
 * The caps that cut model-visible text are character-safe now (see
 * {@see Utf8::cut()}), so this should never fire. It exists because "should
 * never" is not a guarantee: a broken byte can also arrive from an upstream
 * stream, an MCP server or a tool's own encoding bug, and the right response
 * to that is a U+FFFD replacement character in the stored payload — a
 * legible record of what happened — not a failed transaction in the middle of
 * a conversation.
 *
 * Deliberately narrow: only JSON_ERROR_UTF8 is retried after scrubbing. Every
 * other encoding failure (recursion, INF/NAN, an unwritable resource) still
 * throws exactly as before, because scrubbing would not fix those and
 * swallowing them would hide a real bug.
 */
final class Utf8JsonType extends JsonType
{
    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        try {
            return parent::convertToDatabaseValue($value, $platform);
        } catch (SerializationFailed $e) {
            $scrubbed = self::scrubDeep($value);

            if ($scrubbed === $value) {
                // Nothing changed, so the failure was not malformed UTF-8:
                // rethrow the original rather than retrying an identical
                // encode that would fail again with the same message.
                throw $e;
            }

            return parent::convertToDatabaseValue($scrubbed, $platform);
        }
    }

    /**
     * @return mixed the value with every string scrubbed to valid UTF-8
     */
    private static function scrubDeep(mixed $value): mixed
    {
        if (\is_string($value)) {
            return Utf8::scrub($value);
        }

        if (!\is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $clean[\is_string($key) ? Utf8::scrub($key) : $key] = self::scrubDeep($item);
        }

        return $clean;
    }
}
