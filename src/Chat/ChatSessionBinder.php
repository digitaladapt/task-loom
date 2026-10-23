<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\Chat;
use App\Entity\Task;
use App\Entity\TaskKind;
use App\Repository\TaskRepository;

/**
 * Binds a conversation to a session, and reads that binding back as a session
 * (docs/design/SESSION_TASKS.md §8.2, "What this implies for a chat surface";
 * SPEC §15).
 *
 * ## One context, two doors
 *
 * The human wants to talk to *her* — the agent whose objective, notes and
 * history the slices run with — not to a fresh assistant that happens to share a
 * deployment. So a conversation may be about a session, and when it is, the
 * reply is compiled against that session's `## Memories` block (the same
 * renderer the slice uses, so there is exactly one rendering of what she knows),
 * its history, and its standing.
 *
 * ## Why resolution is a method and not a getter
 *
 * Because the binding is a pointer into a chain, not a pointer at an object. A
 * session outlives its task rows: approving an edit disables the original and
 * enables a successor (§8.2), so a chat bound before that edit holds a row that
 * is now archived. The session it names is still there — one row further along.
 * Every read therefore walks to the live end, and there is one place that walk
 * happens rather than one per call site.
 *
 * A binding whose chain ends at an archived row with no successor, or at a row
 * that is no longer a session (neither state is reachable through the approval
 * path, and both would be a bug rather than a state), resolves to null rather
 * than to a guess: a chat with no session is the ordinary chat, and the ordinary
 * chat is a correct thing to be.
 */
final readonly class ChatSessionBinder
{
    public function __construct(private TaskRepository $tasks)
    {
    }

    /**
     * The session this conversation is about *now*, or null.
     *
     * Returns the live end of the chain, which may not be the row the binding
     * was written against — that is the point of resolving rather than storing.
     */
    public function forChat(Chat $chat): ?Task
    {
        $bound = $chat->getSession();
        if (null === $bound) {
            return null;
        }

        $live = $this->tasks->liveEndOfChain($bound);

        // A chain can only end at a session if it began at one, so this is a
        // guard against a hand-edited row rather than an expected branch — but
        // the failure it prevents is a chat compiling its prompt against a
        // task-kind row that has no memory store, which would be a confusing
        // error far from its cause. Cheap, and it names the assumption.
        return TaskKind::Session === $live->getKind() ? $live : null;
    }

    /**
     * Bind this conversation to a session, or unbind it with null.
     *
     * Refuses a task that is not a session, because the whole value of the
     * binding is the memory store behind it and only a session has one. Refusing
     * here rather than rendering nothing later keeps the error next to the
     * operator action that caused it.
     *
     * Resolves to the live end on write too, so a binding made through a stale
     * reference still lands on the session as it is now — the write and the read
     * agree about what "the session" means, which is one rule instead of two.
     *
     * @throws ChatBindingException when the task is not a session
     */
    public function bind(Chat $chat, ?Task $session): void
    {
        if (null === $session) {
            $chat->bindSession(null);

            return;
        }

        $live = $this->tasks->liveEndOfChain($session);

        if (TaskKind::Session !== $live->getKind()) {
            throw ChatBindingException::notASession($live);
        }

        // Only ever cleared and set by the operator (SPEC §15), so a binding is
        // never a second step: re-binding is allowed and simply replaces what
        // the conversation is about, because the alternative — refusing to
        // rebind — would leave an operator unable to correct a mistake.
        $chat->bindSession($live);
    }
}
