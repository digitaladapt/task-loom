<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who wrote a piece of session memory (docs/design/SESSION_TASKS.md §6.3).
 *
 * There are two writers, so provenance is a typed column rather than a
 * sentence in the text — and the two writers' text is not the same kind of
 * thing. Operator entries are advisory directives (steering is the point of
 * the feature); session entries are recollections, possibly written while
 * looking at a hostile tool result. The rendered block tags them
 * `[operator]` / `[you]` and tells the model which is which.
 */
enum SessionMemorySource: string
{
    /** Written by the session itself, mid-work. A recollection. */
    case Session = 'session';

    /** Written by the operator, in the UI. A directive. */
    case Operator = 'operator';
}
