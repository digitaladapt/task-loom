<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How one participant's turn renders to the model (SPEC §15, §2.3).
 *
 * This is the *prompt* vocabulary, not a storage concern: `speaker` says who
 * said it in the world, `role` says which side of the conversation the model
 * sees it on. The mapping is what tells the model whose opinions are whose,
 * which is why it is stored denormalized on the turn rather than re-derived
 * from the roster at render time: a historical turn keeps the role it was
 * rendered with, even if the roster changes underneath it.
 *
 * Closed on purpose, unlike `speaker`. Roles are the OpenAI chat protocol's
 * own two sides, and one of them maps to each participant; the day a third
 * participant joins, it moves *inside* a role (`Nia: …` vs `Reviewer: …`)
 * rather than becoming a third role, because the wire format has only these.
 */
enum TurnRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
