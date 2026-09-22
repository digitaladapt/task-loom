<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How a task declares its toolbox — SPEC §4.1.
 */
enum ToolboxMode: string
{
    /** Resolve against the discovered catalog: task.tags ∩ tool.tags. */
    case Tags = 'tags';

    /** An explicit list of tool names; no tag resolution. */
    case Explicit = 'explicit';
}
