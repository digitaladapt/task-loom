<?php

declare(strict_types=1);

namespace App\Claims;

/**
 * The tables that carry an execution claim (SPEC §6, §15).
 *
 * A claimable aggregate is one that a worker takes ownership of for the
 * duration of exactly one message: a `run` (one LLM request, or one set of
 * tool calls) or a `chat_exchange` (one reply). The claim protocol — the
 * `lock_version`/`claimed_at`/`claim_fleet` trio, the atomic claim UPDATE,
 * the lease — is identical for both, so the machinery is written once
 * against this list rather than copied per aggregate.
 *
 * An enum rather than a string parameter, deliberately: the table name is
 * interpolated into the claim SQL, so the set of legal values has to be
 * closed at compile time. A string parameter here would be an injection
 * surface that a future caller only has to be careless once to open.
 */
enum ClaimTarget: string
{
    case Run = 'run';

    /** SPEC §15: one chat exchange — the run-analog for a conversation. */
    case ChatExchange = 'chat_exchange';
}
