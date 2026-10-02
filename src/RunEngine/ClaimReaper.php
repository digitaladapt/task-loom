<?php

declare(strict_types=1);

namespace App\RunEngine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Clears execution claims held by the fleet this process is replacing
 * (SPEC §6.2).
 *
 * ## What the first cut got wrong
 *
 * It inferred "abandoned" from claim age, reusing the engine's
 * CLAIM_STALE_SECONDS lease. That is the conservative rule and it is always
 * *safe* — but it is not the rule this situation needs, and the field proved it
 * within a day: a container `down`'d and `up`'d inside a minute leaves claims
 * seconds old. The sweep reported "cleared 0, 2 left held (still fresh)",
 * requeue then correctly re-dispatched the owed turns, and every one of those
 * messages was dropped on arrival — because taking over a claim requires the
 * same hour, so the re-dispatched work hit a lock nobody would release for
 * another 59 minutes. Recovery that looked like recovery and did nothing.
 *
 * The missing fact was never age. It was **identity**: the dead owner and the
 * living one were indistinguishable because nobody had asked *whose* claim it
 * was.
 *
 * ## The rule now
 *
 * A claim is cleared when its recorded owner is the fleet id this process was
 * started with — see FleetId, and the entrypoint that issues one per container
 * start. "My predecessor's claim" is a fact about identity, so it does not
 * depend on the clock at all: it holds whether the fleet died ten seconds or
 * ten hours ago, and whether the container came back on the same host or a
 * different one.
 *
 * ## What it still refuses to touch
 *
 * - **A claim from another fleet.** That fleet may be running this very second
 *   (a workers-only host beside a UI, sharing one database). Not abandoned,
 *   however old it looks. It keeps the lease.
 * - **A claim with no fleet recorded** — a legacy row, or a turn taken by a
 *   process that has no fleet identity at all (a one-shot `app:run:now` in a
 *   terminal). Nothing is proven about it, so nothing is done to it: the lease
 *   handles it, slowly and safely. This is the case that makes `claim_fleet`
 *   nullable rather than defaulted.
 *
 * "When in doubt, do nothing" is the whole safety argument. Every uncertainty
 * resolves to the lease, and the lease cannot be wrong.
 *
 * ## What it does not do
 *
 * It clears `claimed_at` and the fleet stamp, and bumps `lock_version` (the
 * ownership token — a predecessor that somehow came back must not be able to
 * clear a successor's claim). It does not touch `status`, `step_count`, the
 * checkpoint, or anything else the interrupted turn committed, because a turn
 * commits nothing until it returns: the committed state is already where the
 * work resumes from. Enabling the re-delivery is this class's job; deriving and
 * dispatching the owed work is `app:run:requeue`'s.
 */
final readonly class ClaimReaper
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Clear every claim this fleet's predecessor left behind.
     *
     * @return int the number of claims cleared
     */
    public function reap(): int
    {
        $fleet = FleetId::current();

        if (null === $fleet) {
            // Not a fleet: nothing is provably abandoned, so nothing is
            // cleared. Refusing here rather than at the caller keeps the
            // guarantee in one place — reap() can never clear a claim it
            // cannot attribute.
            return 0;
        }

        return $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = NULL, claim_fleet = NULL WHERE claimed_at IS NOT NULL AND claim_fleet = :fleet',
            ['fleet' => $fleet],
        );
    }

    /**
     * What reap() would clear, and what it deliberately will not.
     *
     * The three counts are separated because they mean different things to the
     * operator reading the boot log: `stale` is work waiting on the engine's
     * lease (a foreign fleet, or a claim with no recorded owner), while
     * `foreign` is *usually* a live fleet elsewhere — no action, but silently
     * lumping it in with "stale" is how you spend an afternoon wondering why
     * the sweep keeps declining to act.
     *
     * @return array{mine: int, foreign: int, unowned: int}
     */
    public function survey(): array
    {
        $connection = $this->em->getConnection();
        $fleet = FleetId::current();

        $mine = null === $fleet ? 0 : $connection->fetchOne(
            'SELECT COUNT(*) FROM run WHERE claimed_at IS NOT NULL AND claim_fleet = :fleet',
            ['fleet' => $fleet],
        );

        return [
            'mine' => \is_numeric($mine) ? (int) $mine : 0,
            'foreign' => $this->count(
                'SELECT COUNT(*) FROM run WHERE claimed_at IS NOT NULL AND claim_fleet IS NOT NULL AND claim_fleet != :fleet',
                ['fleet' => $fleet ?? ''],
            ),
            'unowned' => $this->count('SELECT COUNT(*) FROM run WHERE claimed_at IS NOT NULL AND claim_fleet IS NULL'),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $sql, array $params = []): int
    {
        $value = $this->em->getConnection()->fetchOne($sql, $params);

        return \is_numeric($value) ? (int) $value : 0;
    }
}
