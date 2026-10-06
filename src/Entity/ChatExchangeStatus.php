<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A conversation's lifecycle states (SPEC §15).
 *
 * Deliberately *not* `RunStatus`, and deliberately smaller. A run's states
 * encode justified completion and the ways a task can fail to justify it
 * (`incomplete`, `needs_attention`, `paused`); a conversation has none of
 * that. It does not terminate, it does not declare itself done, and a
 * conversation that needs a human is simply a conversation waiting for a
 * reply. Sharing the run's enum would drag three states in that have no
 * meaning here and would make `isTerminal()` a lie.
 */
enum ChatExchangeStatus: string
{
    /** Created, waiting for a worker to pick it up. */
    case Queued = 'queued';

    /** On the wire — the model has been asked and has not answered. */
    case Running = 'running';

    /** The reply is committed. Not "the conversation is over" — it never is. */
    case Answered = 'answered';

    /**
     * The exchange could not produce a reply.
     *
     * Terminal for the *exchange*, not for the conversation: the next thing
     * the human types starts a new one. What this state must never become is
     * a silent dead end — a person is waiting on the other end of this, which
     * is why the chat surface reads it and says so rather than showing a
     * spinner forever (SPEC §15).
     */
    case Failed = 'failed';
}
