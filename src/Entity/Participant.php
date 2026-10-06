<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One participant in a conversation (SPEC §15, §2.4).
 *
 * A roster rather than a hardcoded pair, because the two-party case is the
 * one that is *cheap* to generalize now and expensive to generalize later:
 * the schema does not assume two, so a third participant (the reviewer pass,
 * a second human, another persona) is a new case here plus a name prefix
 * inside the role — not a rewrite. Role alone stops disambiguating at three
 * speakers, which is why `displayName` exists from the start even though the
 * two-party prompt deliberately renders roles only and never prefixes.
 *
 * `speaker` on a turn is one of these values, so attribution is validated
 * against a closed set at both ends: a turn can only be written as somebody
 * the system knows, and the roster is what maps a speaker to a role.
 */
enum Participant: string
{
    /** The human. */
    case Andrew = 'andrew';

    /** The assistant — "you" as far as the model is concerned. */
    case Nia = 'nia';

    /**
     * The name to render when a transcript has to disambiguate more than one
     * speaker inside a role. Unused by the two-party prompt (§2.5 locked
     * roles-only, no per-turn prefix), which is exactly why it is a value on
     * the roster and not logic in the renderer.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Andrew => 'Andrew',
            self::Nia => 'Nia',
        };
    }
}
