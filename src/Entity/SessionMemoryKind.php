<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What a piece of session memory is (docs/design/SESSION_TASKS.md §3.2):
 * the singular objective, or one of the plural notes.
 *
 * The two differ in cardinality and lifecycle — the objective is one per
 * session, always injected, never aged; notes accumulate and are capped —
 * which is why they are a kind column on one table rather than two tables:
 * they share storage, provenance, revisions and rendering, and differ only
 * in the rules the store applies to them.
 */
enum SessionMemoryKind: string
{
    /** The current "what it is trying to achieve" — one per session. */
    case Objective = 'objective';

    /** "Where things are" — many per session, governed by the caps. */
    case Note = 'note';
}
