<?php

declare(strict_types=1);

namespace App\Claims;

use App\RunEngine\FleetId;
use App\RunEngine\RunEngine;
use Doctrine\DBAL\Connection;

/**
 * The execution-claim protocol, written once and used by every claimable
 * aggregate (SPEC §6, §15).
 *
 * ## Why this exists as its own class
 *
 * Chat reuses the run engine's *machinery* without reusing its tables
 * (`docs/design/CHAT_AND_CAPACITY.md` §3.3): a chat exchange is claimed,
 * leased, released and reaped exactly as a run is, but a chat is not a
 * `Run` and does not get to be one. The design's mitigation for that
 * duplication is to share the implementation rather than the schema — so
 * the claim UPDATE, its lease, and the release-token check live here, and
 * both aggregates go through them. Adding a third claimable aggregate is
 * then a new `ClaimTarget` case, not a third copy of a subtle protocol.
 *
 * ## The protocol, and what each part is for
 *
 * - **The claim is one atomic UPDATE whose WHERE clause is the mutex.** It
 *   succeeds only when no *live* claim is held, where live means
 *   `claimed_at > now - CLAIM_STALE_SECONDS`. The engine's own staleness
 *   window is adopted rather than re-invented: two definitions of
 *   "abandoned" in one system is how a run gets taken over twice.
 * - **`lock_version` is the ownership token.** Every claim bumps it, and
 *   `release()` clears the claim only while the token still matches, so a
 *   worker whose claim was taken over cannot clear its successor's.
 * - **`claim_fleet` is the owner label.** Written for explanation, never
 *   for authorisation — see `FleetId`, whose docblock is the record of the
 *   boot sweep that was built on the opposite (and impossible) idea.
 *
 * Written via raw SQL, not the ORM, for the same reason the engine does it:
 * entity flushes write changed fields only, so a flush can never clobber
 * these columns, and the claim can never be incidentally released by a
 * persist somewhere else in the request.
 */
final readonly class ClaimStore
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Take the claim on one row for the duration of one message.
     *
     * @return int|null the claim token (the new `lock_version`), or null when
     *                  another worker holds a live claim and the delivery
     *                  must be dropped
     */
    public function claim(ClaimTarget $target, int $id): ?int
    {
        $now = time();

        $updated = $this->connection->executeStatement(
            \sprintf(
                'UPDATE %s SET lock_version = lock_version + 1, claimed_at = :now, claim_fleet = :fleet WHERE id = :id AND (claimed_at IS NULL OR claimed_at <= :staleBefore)',
                $target->value,
            ),
            [
                'now' => $now,
                'fleet' => FleetId::current(),
                'id' => $id,
                'staleBefore' => $now - RunEngine::CLAIM_STALE_SECONDS,
            ],
        );

        if (1 !== $updated) {
            return null;
        }

        // Read the token back after the UPDATE: until the fresh claimed_at
        // goes stale, nobody else may alter either column, so this read is
        // race-free by construction.
        $token = $this->connection->fetchOne(
            \sprintf('SELECT lock_version FROM %s WHERE id = :id', $target->value),
            ['id' => $id],
        );

        return \is_numeric($token) ? (int) $token : null;
    }

    /**
     * Release the claim — only if it is still ours. Idempotent by design:
     * the engine's commit path releases inside the committing transaction
     * and the turn entry point's `finally` releases again, and the second
     * call is a no-op.
     */
    public function release(ClaimTarget $target, int $id, int $token): void
    {
        $this->connection->executeStatement(
            \sprintf('UPDATE %s SET claimed_at = NULL, claim_fleet = NULL WHERE id = :id AND lock_version = :token', $target->value),
            ['id' => $id, 'token' => $token],
        );
    }

    /**
     * Clear every claim on this table that the booting fleet can prove is
     * nobody's, returning how many were cleared.
     *
     * The rule and its reasoning belong to `ClaimReaper`; this is only its
     * one statement, shared so that adding an aggregate cannot silently
     * exempt it from boot recovery.
     */
    public function reap(ClaimTarget $target, int $grabAfterSeconds): int
    {
        return $this->connection->executeStatement(
            \sprintf('UPDATE %s SET lock_version = lock_version + 1, claimed_at = NULL, claim_fleet = NULL WHERE claimed_at IS NOT NULL AND claimed_at <= :cutoff', $target->value),
            ['cutoff' => time() - $grabAfterSeconds],
        );
    }

    /**
     * What `reap()` would clear, and what it would leave, for one table.
     *
     * @return array{clearable: int, leased: int, unowned: int}
     */
    public function survey(ClaimTarget $target, int $grabAfterSeconds): array
    {
        $cutoff = time() - $grabAfterSeconds;
        $table = $target->value;

        return [
            'clearable' => $this->count(\sprintf('SELECT COUNT(*) FROM %s WHERE claimed_at IS NOT NULL AND claimed_at <= :cutoff', $table), ['cutoff' => $cutoff]),
            'leased' => $this->count(\sprintf('SELECT COUNT(*) FROM %s WHERE claimed_at IS NOT NULL AND claimed_at > :cutoff', $table), ['cutoff' => $cutoff]),
            'unowned' => $this->count(\sprintf('SELECT COUNT(*) FROM %s WHERE claimed_at IS NOT NULL AND claimed_at <= :cutoff AND claim_fleet IS NULL', $table), ['cutoff' => $cutoff]),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $sql, array $params = []): int
    {
        $value = $this->connection->fetchOne($sql, $params);

        return \is_numeric($value) ? (int) $value : 0;
    }
}
