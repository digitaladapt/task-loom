<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\Task;

/**
 * A conversation was asked to be about something other than a session
 * (SPEC §15; docs/design/SESSION_TASKS.md §8.2).
 *
 * A binding's only value is the memory store behind it, and only a session has
 * one — so this is refused at the point of the operator's action rather than
 * silently rendering nothing on the next reply, where the cause would be a page
 * behind the symptom.
 *
 * Carries the offending task so a controller can name it without re-reading it.
 */
final class ChatBindingException extends \RuntimeException
{
    private function __construct(string $message, private readonly ?Task $task)
    {
        parent::__construct($message);
    }

    public static function notASession(Task $task): self
    {
        return new self(\sprintf(
            'Task "%s" is a %s task, and only a session can be the subject of a conversation — a chat bound to it would have no memory store to read (SPEC §15, SESSION_TASKS.md §8.2).',
            $task->getTitle(),
            $task->getKind()->value,
        ), $task);
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }
}
