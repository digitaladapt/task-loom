<?php

declare(strict_types=1);

namespace App\RunEngine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Clears execution claims whose owner is known to be gone (SPEC §6.2).
 *
 * The engine's claim is a lease, not a lock: a claim abandoned by a dead
 * worker is taken over after RunEngine::CLAIM_STALE_SECONDS. That lease is
 * the right *general* answer — it needs no coordination, and it cannot be
 * wrong. It is the wrong answer in exactly one situation: when the process
 * that owns the claim is provably gone, waiting an hour to notice is an hour
 * of work that is already committable.
 *
 * Boot is that situation, and only when the booting process group owns the
 * fleet — see FleetOwnership for why "I am running" is not sufficient, and
 * why the authority is granted rather than assumed.
 *
 * **What this does and does not do.** It clears `claimed_at`, which is the
 * whole of the claim's liveness; it does not touch `status`, `step_count`,
 * the checkpoint, or anything else the interrupted turn committed — because
 * a turn commits nothing until it returns, so the committed state is already
 * the state the work should resume from. Reaping is the *enabling* half;
 * re-deriving and re-dispatching the owed work is `app:run:requeue`'s job
 * (RunRequeueCommand), and the two are meant to run in that order.
 *
 * `lock_version` is incremented rather than left alone, for the same reason
 * the engine's own takeover increments it: it is the ownership token. A
 * worker that held the claim before the restart must not be able to clear a
 * successor's claim with a token that still matches. (It cannot: a dead
 * worker does not write again. The increment is defence in depth, and it
 * keeps "a claim changed hands" visible in the one column that records it.)
 */
final readonly class ClaimReaper
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Clear every claim older than the staleness window — i.e. adopt the
     * engine's own definition of "abandoned" instead of inventing a second
     * one.
     *
     * A fresh claim is deliberately left alone. Boot normally means no worker
     * is alive, but the same command is reachable from an operator, and the
     * transition is not instantaneous: a run-now in flight, or a worker
     * recycling on its --time-limit, can hold a claim while a sweep runs. The
     * lease makes that case safe by construction rather than by luck.
     *
     * @return int the number of claims cleared
     */
    public function reap(): int
    {
        return $this->em->getConnection()->executeStatement(
            'UPDATE run SET lock_version = lock_version + 1, claimed_at = NULL WHERE claimed_at IS NOT NULL AND claimed_at <= :staleBefore',
            ['staleBefore' => time() - RunEngine::CLAIM_STALE_SECONDS],
        );
    }

    /**
     * What reap() would clear, for --dry-run and for reporting.
     *
     * @return array{reapable: int, held: int} reapable = past the window, held = claimed but still fresh
     */
    public function survey(): array
    {
        $connection = $this->em->getConnection();
        $staleBefore = time() - RunEngine::CLAIM_STALE_SECONDS;

        $reapable = $connection->fetchOne(
            'SELECT COUNT(*) FROM run WHERE claimed_at IS NOT NULL AND claimed_at <= :staleBefore',
            ['staleBefore' => $staleBefore],
        );

        $held = $connection->fetchOne(
            'SELECT COUNT(*) FROM run WHERE claimed_at IS NOT NULL AND claimed_at > :staleBefore',
            ['staleBefore' => $staleBefore],
        );

        return [
            'reapable' => \is_numeric($reapable) ? (int) $reapable : 0,
            'held' => \is_numeric($held) ? (int) $held : 0,
        ];
    }
}
