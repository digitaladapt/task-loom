<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What triggered an exchange (SPEC §15) — the `RunTrigger` precedent exactly.
 *
 * In this version `inbound` is the only case that is ever written, because v1
 * is respond-only: the assistant answers, she does not open a conversation.
 * The case exists anyway, and exists *now*, because the deliverable for
 * respond-only is explicitly that initiation later is an addition rather than
 * a redesign, and the cheapest way to guarantee that is to have the field
 * already modelled. When the assistant initiates, this enum grows a
 * `scheduled`/`internal` case and nothing else changes: no new table, no
 * nullable aggregate, no migration of existing rows.
 *
 * The one thing a future change must not do is make "an exchange always has a
 * triggering inbound turn" load-bearing — a non-null FK with no escape. This
 * enum is what keeps that from hardening: the trigger is already a *value* on
 * the exchange, not the presence of a message.
 */
enum ChatTrigger: string
{
    /** A turn from a participant — the only v1 value. */
    case Inbound = 'inbound';
}
