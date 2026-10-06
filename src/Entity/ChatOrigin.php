<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The surface a turn came through (SPEC §15).
 *
 * Not a transport id, and not a per-message reference: the exchange record is
 * deliberately surface-agnostic so that adding a surface is an addition
 * rather than a migration. An earlier draft of the design carried a
 * `transport_ref` field holding the delivery transport's own message id, and
 * it was deleted — that shape presumed the conversation lives inside a
 * transport's id space, which is only true if the chat *is* the transport.
 * Here the endpoint is the interface, so the surface only has to name itself.
 */
enum ChatOrigin: string
{
    /** The phone-first web chat this version ships (SPEC §8, §15). */
    case Web = 'web';
}
