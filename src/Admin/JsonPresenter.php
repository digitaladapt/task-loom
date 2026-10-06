<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * JSON for a human to read, including JSON that is *inside* a string.
 *
 * ## The problem this solves
 *
 * A tool result is stored as an array whose `content` is a **string** — that is
 * the OpenAI wire shape (a `tool` message's content is text), and the MCP layer
 * agrees: a `CallToolResult` carries text parts, so the executor joins them
 * (`ToolExecutor::resultToArray`). When the tool is a real one that returns
 * structured data, that string *is JSON*. Pretty-printing the payload therefore
 * produced JSON on the outside and one long line of escaped JSON on the inside,
 * which is the worst of both: outermost structure readable, the part you
 * actually wanted to read not.
 *
 * So: decode the payload, and wherever a string value is itself JSON, decode
 * that too, and print the whole thing pretty. It is the same document, shown
 * once, at every level it was encoded.
 *
 * **This is a display concern only.** Nothing here touches what the model
 * receives: the wire carries the joined text exactly as the tool returned it,
 * because that is what the endpoint's contract asks for, and the model reads
 * JSON far better than it reads prose about JSON. Only the page changes.
 *
 * A string that is not JSON is left exactly as it was, so prose stays prose.
 */
final class JsonPresenter
{
    /** How deep to keep unwrapping nested JSON strings before giving up. */
    private const int MAX_DEPTH = 6;

    /**
     * Pretty JSON for display: nested JSON strings unwrapped, in original order.
     */
    public function pretty(mixed $value): string
    {
        $encoded = json_encode(
            $this->unwrap($value, 0),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        // json_encode fails on invalid UTF-8 or recursion, and a page that
        // shows nothing is worse than one that shows the raw value.
        if (false === $encoded) {
            return \is_string($value) ? $value : var_export($value, true);
        }

        return $encoded;
    }

    /**
     * Whether a string is JSON worth unwrapping.
     *
     * Deliberately strict: an object or array, not a bare scalar. `"3"`,
     * `"true"` and `"null"` are JSON, and turning those into 3/true/null would
     * quietly change how a value reads ("the count is \"3\"" is not the same
     * statement as "the count is 3", and the tool chose the former).
     */
    public function isJsonStructure(string $value): bool
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return false;
        }

        $first = $trimmed[0];
        if ('{' !== $first && '[' !== $first) {
            return false;
        }

        json_decode($trimmed, true);

        return \JSON_ERROR_NONE === json_last_error();
    }

    private function unwrap(mixed $value, int $depth): mixed
    {
        if (\is_string($value)) {
            if ($depth >= self::MAX_DEPTH || !$this->isJsonStructure($value)) {
                return $value;
            }

            return $this->unwrap(json_decode(trim($value), true), $depth + 1);
        }

        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->unwrap($item, $depth + 1);
            }

            return $out;
        }

        return $value;
    }
}
