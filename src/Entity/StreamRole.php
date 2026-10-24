<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Which side of the conversation a stream row renders on
 * (docs/design/THE_STREAM.md §2.1).
 *
 * This is the wire's own vocabulary, not a description of the writer: the
 * endpoint knows `user`, `assistant` and `tool`, and a row that cannot be
 * stated in one of those three cannot be sent at all. `tool` is separate from
 * `assistant` because the endpoint treats it separately — a `tool` message is
 * keyed to a `tool_call_id` and is only legal directly after the assistant
 * message that announced the call.
 */
enum StreamRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
