<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Run lifecycle states (SPEC §7).
 */
enum RunStatus: string
{
    /** Created, waiting for the engine to pick it up (FIFO persisted queue). */
    case Queued = 'queued';

    /** On the wire — an LLM step or tool call in flight. */
    case Running = 'running';

    /** Circuit-breaker tripped or run paused by the engine; needs attention. */
    case Paused = 'paused';

    /** Completed with a justified completion declaration (SPEC §5.4). */
    case Succeeded = 'succeeded';

    /** Hit a budget without a completion declaration — never 'succeeded'. */
    case Incomplete = 'incomplete';

    /** Terminal error. */
    case Failed = 'failed';

    /** Repetition circuit-breaker or persistent attention-worthy state. */
    case NeedsAttention = 'needs_attention';
}
