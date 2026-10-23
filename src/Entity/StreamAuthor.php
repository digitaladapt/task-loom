<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who wrote a row into the stream (docs/design/THE_STREAM.md §2.1).
 *
 * The stream belongs to the being, not to a surface, so this is the only place
 * a caller says *where a turn came from*. It is deliberately not "which run"
 * or "which chat": those are surfaces, and a surface is a door, not an author.
 * `scheduler` exists as its own case because a heartbeat-driven turn has a
 * different provenance from her own continuation — the moment the two collapse
 * into `being`, "why did she turn at 3am" stops being answerable from the row.
 */
enum StreamAuthor: string
{
    /** The human, typing on any surface. */
    case Operator = 'operator';

    /** Her, continuing. The default author of a turn nobody asked for. */
    case Being = 'being';

    /** The heartbeat launching a turn because she was owed one. */
    case Scheduler = 'scheduler';
}
