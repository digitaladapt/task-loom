<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * The two primitives of a tool call that are identical wherever a tool call
 * happens, and identical in no other respect to anything else (SPEC §5.1).
 *
 * These live outside `RunEngine` because chat runs tool calls too (`SPEC §15`,
 * `docs/design/CHAT_TOOLS.md`) and the two callers must agree exactly: two
 * implementations of "is this the same call?" or "how do errors go back to the
 * model?" is how a model gets inconsistent feedback for the same mistake, and
 * how a duplicate-suppression rule quietly stops matching.
 *
 * What is *not* here, deliberately: anything that decides what happens on
 * failure. A run retries with a circuit breaker against a frozen toolbox; a
 * chat reports the error back and keeps talking. That difference is the
 * caller's, and factoring it in here would be the shared-machinery mistake in
 * reverse — a helper that assumes one caller's policy.
 */
final class ToolCallPrimitives
{
    /**
     * Drop exact within-turn repeats from a model's tool-call list: the same
     * tool name with arguments that are equal as data (key order is not
     * semantic difference).
     *
     * Order is preserved and the FIRST occurrence wins, so the surviving call
     * keeps its original id — and the replayed assistant message (`toolCalls`)
     * matches the results the tool turn will produce, which the endpoint
     * requires. Deliberately narrower than the run digest's repetition metric:
     * that one counts repeats ACROSS a run to diagnose the model, while this
     * removes them WITHIN a single turn to stop paying for them.
     *
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $calls
     *
     * @return array{0: list<array{id: string, name: string, arguments: array<string, mixed>}>, 1: list<array{id: string, name: string, arguments: array<string, mixed>}>} the kept calls and the dropped ones
     */
    public static function dropDuplicateCalls(array $calls): array
    {
        $seen = [];
        $kept = [];
        $dropped = [];

        foreach ($calls as $call) {
            $signature = $call['name'].'|'.self::canonicalJson($call['arguments']);

            if (isset($seen[$signature])) {
                $dropped[] = $call;
                continue;
            }

            $seen[$signature] = true;
            $kept[] = $call;
        }

        return [$kept, $dropped];
    }

    /**
     * A stable string for any argument tree: keys sorted at every depth, so
     * two calls that differ only in key order compare equal. Mirrors
     * {@see \App\Admin\RunDigest}'s canonicalisation — the two must agree on
     * what "the same call" means.
     */
    public static function canonicalJson(mixed $value): string
    {
        if (\is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            $value = array_map(self::canonicalJson(...), $value);
        }

        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Structured, actionable error fed back to the model so the loop
     * self-corrects (SPEC §5.1).
     *
     * @return string JSON
     */
    public static function errorFeedbackJson(string $error, string $tool, string $detail, ?int $attempt = null): string
    {
        $payload = [
            'error' => $error,
            'tool' => $tool,
            'detail' => $detail,
        ];

        if (null !== $attempt) {
            $payload['attempt'] = $attempt;
        }

        return (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
