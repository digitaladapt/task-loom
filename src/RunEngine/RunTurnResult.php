<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * What a single turn's execution leaves behind — the engine's answer to
 * "what does this run need next?".
 *
 * The transport-free contract between the engine and its callers: the
 * Messenger handlers turn AwaitToolTurn / AwaitLlmTurn into the next lane
 * message; the synchronous run loop just keeps looping; Done ends it. Stale
 * is a dropped message (duplicate delivery, late redelivery, terminal run):
 * the ledger and run state were already past the message's step, so doing
 * nothing IS the correct processing.
 *
 * @internal
 */
enum RunTurnResult
{
    /** LLM turn done with tool calls — the run is parked mid-exchange, awaiting its tool turn. */
    case AwaitToolTurn;

    /** Tool turn done — the next LLM turn is due. */
    case AwaitLlmTurn;

    /** The run is terminal (or has nothing left to do): stop. */
    case Done;

    /** The delivered message no longer matches the run's state: drop it. */
    case Stale;
}
