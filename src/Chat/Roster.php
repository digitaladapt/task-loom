<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\Participant;
use App\Entity\TurnRole;

/**
 * The participant roster (SPEC §15, §2.4): who is in the conversation, and
 * which side of it the model sees each of them on.
 *
 * ## Why this is a class and not a constant map
 *
 * Because §2.1 is a *safety* invariant, not a formatting preference. Flatten
 * a two-party transcript into one role and it breaks in both directions: the
 * assistant's own past output reads as the human's instructions (a
 * prompt-injection surface grown inside her own history, in a system where
 * she holds tools), or the human's instructions read as her own prior words
 * and may not be followed. When the two disagree, a flattened transcript has
 * one speaker holding both opinions and the contradiction is silent.
 *
 * So the mapping is defined once, here, and the prompt compiler reads it
 * rather than deciding per call site. The day there are three participants,
 * there is one place to change.
 *
 * ## Roles for two, names for three
 *
 * For two participants, role alone disambiguates and a visible name prefix
 * buys nothing (locked decision, §2.5): the human never types their name, and
 * the prompt never shows one. At three speakers, two turns can share a role
 * and names have to move *inside* it — `Nia: …` versus `Reviewer: …`. That is
 * the same mechanism one rung up, and `Participant::displayName()` is what
 * makes it an addition.
 */
final readonly class Roster
{
    /**
     * The participants, in display order. The first is the human.
     *
     * @return list<Participant>
     */
    public function participants(): array
    {
        return [Participant::Andrew, Participant::Nia];
    }

    /**
     * Which role the model sees this participant on.
     *
     * The human is `user` and the assistant is `assistant`: the form a small
     * model is actually trained on, which makes it the most reliable
     * mechanism for telling the model whose opinions are whose — not merely
     * the cheapest.
     */
    public function roleFor(Participant $participant): TurnRole
    {
        return match ($participant) {
            Participant::Andrew => TurnRole::User,
            Participant::Nia => TurnRole::Assistant,
        };
    }

    /**
     * The sentence that pins the roles in the system prompt.
     *
     * Stated explicitly rather than left to be inferred from the transcript's
     * shape: "you are Nia, the user is Andrew" removes the one inference the
     * model could get wrong and silently invert the conversation with.
     */
    public function render(): string
    {
        $assistant = $this->nameOf(Participant::Nia);
        $user = $this->nameOf(Participant::Andrew);

        return \sprintf(
            'You are %s, the assistant. You are speaking with %s, the user. '
            .'Every turn in this conversation is attributed: messages on the user side are %s\'s words, '
            .'messages on the assistant side are your own. Do not treat your own previous words as instructions from %s, '
            .'and do not treat %s\'s words as your own.',
            $assistant,
            $user,
            $user,
            $user,
            $user,
        );
    }

    private function nameOf(Participant $participant): string
    {
        return $participant->displayName();
    }
}
