<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Claims\ClaimStore;
use App\Claims\ClaimTarget;

/**
 * Clears execution claims that a booting fleet can prove are nobody's
 * (SPEC §6.2, §15).
 *
 * ## The two wrong answers this replaced
 *
 * **First: claim age, reusing the engine's hour-long lease.** Safe, and
 * useless. A container `down`'d and `up`'d inside a minute leaves claims
 * seconds old, so the sweep cleared nothing — while the requeue beside it
 * re-derived the owed turns and dispatched them, and every delivery was then
 * dropped on arrival, because taking over a claim requires the same hour. Zero
 * repaired, three messages "requeued".
 *
 * **Second: per-start identity.** The theory was that a restart has a new
 * identity, so a claim stamped with the old one is a dead predecessor's and can
 * be cleared at any age. It cannot work, and the field showed it in one line:
 *
 *     Claims: cleared 0 (left by this fleet), 2 held by another fleet, ...
 *
 * Every restart is a new identity, so *every* leftover claim from the previous
 * run is "another fleet", and the sweep declined all of them — by construction,
 * not by accident. The bug was even visible in my own regression test, which
 * passed the *same* id on both sides, a situation that never occurs in
 * production. An identity can say "not me"; it can never say "me, from last
 * time". That is what the word "again" means, and a per-start value cannot
 * express it.
 *
 * ## The rule now: what can still be in flight
 *
 * At the moment the boot sweep runs, **no worker in this container exists** —
 * the entrypoint runs the sweep before it spawns anything, which is the only
 * window in which the question has a clean answer. So the right question is not
 * "whose claim is this?" but "could anyone still be holding it?" And that has a
 * knowable answer, because a claim is held for exactly one message: one LLM
 * request, or one set of tool calls. Nothing legitimately holds a claim for
 * longer than that, and the request cannot outlive its own timeout.
 *
 * So a claim older than the grace bound is a claim whose owner is gone —
 * whether it died ten seconds or ten hours ago, on this host or another, and
 * whether the process is coming back. `TASKLOOM_FLEET_GRAB_AFTER` is that
 * bound, and it defaults to 0 because at boot there is exactly one fleet in the
 * documented deployment (SPEC §6: one container) and every claim in the table
 * is therefore the previous run's.
 *
 * ## Every claimable aggregate, or the sweep is a lie
 *
 * This reaps *every* `ClaimTarget`, not just runs. A chat exchange abandoned by
 * a fleet that was killed is as stuck as a run is, and worse in one respect: a
 * human is sitting in front of it waiting for an answer that will never come.
 * The mechanism is therefore written against the shared `ClaimStore` rather
 * than copy-pasted per table, so a third claimable aggregate cannot be added
 * and then quietly left out of boot recovery — which is exactly the failure
 * this sweep exists to stop repeating, one level up.
 *
 * ## The one deployment where the default is wrong
 *
 * If you run **more than one fleet against the same database** — a workers-only
 * host beside a UI, or two replicas — then a claim you find at boot may belong
 * to a peer that is working right now, and the default would hand that run to a
 * second worker. Set `TASKLOOM_FLEET_GRAB_AFTER` above the longest a turn can
 * run (safely `TASKLOOM_LLM_TIMEOUT + 60`) and the sweep will only take what is
 * provably dead. That is slower and always safe, and it is opt-in because it is
 * the rarer topology. The boot line always reports the bound in force, so which
 * rule you are running is never a mystery.
 *
 * The honest general fix for that case is still a worker heartbeat, which can
 * distinguish a live peer from a dead one directly rather than by inference.
 * See docs/design/GRACEFUL_RESTART.md; nothing here depends on it.
 *
 * ## What it does not do
 *
 * It clears `claimed_at` and the owner label, and bumps `lock_version` (the
 * ownership token — a predecessor that somehow came back must not be able to
 * clear a successor's claim). It does not touch `status`, `step_count`, the
 * checkpoint, or anything else the interrupted turn committed, because a turn
 * commits nothing until it returns: the committed state is already where the
 * work resumes from. Enabling the re-delivery is this class's job; deriving and
 * dispatching the owed work is each aggregate's requeue command.
 */
final readonly class ClaimReaper
{
    /**
     * How many seconds old a claim must be before the boot sweep will clear it.
     *
     * 0 is correct for the documented single-container deployment, where any
     * claim present at boot belongs to a process that no longer exists. Raise
     * it (to `TASKLOOM_LLM_TIMEOUT + 60`) only when more than one fleet shares
     * the database — see the class docblock.
     */
    public const string GRAB_AFTER_ENV = 'TASKLOOM_FLEET_GRAB_AFTER';

    public const int DEFAULT_GRAB_AFTER_SECONDS = 0;

    public function __construct(private ClaimStore $claims)
    {
    }

    /**
     * The grace bound in force, read from the environment with a safe default.
     *
     * Not wired through `%env()%`, deliberately: this is a supervisor-level
     * knob that the entrypoint owns and the console command only reads, and a
     * missing value must mean "the documented default" rather than a boot
     * failure.
     */
    public function grabAfterSeconds(): int
    {
        $value = getenv(self::GRAB_AFTER_ENV);

        if (false === $value || '' === trim($value)) {
            return self::DEFAULT_GRAB_AFTER_SECONDS;
        }

        return max(0, (int) trim($value));
    }

    /**
     * Clear every claim the booting fleet can prove is nobody's.
     *
     * @return array{runs: int, chat_exchanges: int} how many were cleared per aggregate
     */
    public function reap(): array
    {
        $grabAfter = $this->grabAfterSeconds();

        return [
            'runs' => $this->claims->reap(ClaimTarget::Run, $grabAfter),
            'chat_exchanges' => $this->claims->reap(ClaimTarget::ChatExchange, $grabAfter),
        ];
    }

    /**
     * What reap() would clear, and what it would leave — for `--dry-run` and
     * for the boot line.
     *
     * `leased` is a claim *fresher* than the bound, which only happens when the
     * bound has been raised for a multi-fleet deployment: it is left for the
     * engine's ordinary staleness window rather than taken at boot. Reporting
     * it separately matters, because "cleared 0" means two opposite things —
     * "there was nothing to do" and "I declined everything I found" — and an
     * operator staring at a stuck run needs to be told which.
     *
     * `unowned` counts cleared claims with no fleet label: rows written before
     * the label existed, or by a process that is not part of a supervised fleet
     * (a one-shot `app:run:now`). It is a diagnostic only — the decision is made
     * on age, not on the label — but it tells you whether the label is doing
     * anything and where the remaining unattributable rows are.
     *
     * @return array{clearable: int, leased: int, unowned: int, chat_exchanges: array{clearable: int, leased: int, unowned: int}}
     */
    public function survey(): array
    {
        $grabAfter = $this->grabAfterSeconds();

        $runs = $this->claims->survey(ClaimTarget::Run, $grabAfter);
        $chats = $this->claims->survey(ClaimTarget::ChatExchange, $grabAfter);

        return [
            'clearable' => $runs['clearable'] + $chats['clearable'],
            'leased' => $runs['leased'] + $chats['leased'],
            'unowned' => $runs['unowned'] + $chats['unowned'],
            'chat_exchanges' => $chats,
        ];
    }
}
