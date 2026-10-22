<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Run;
use App\Entity\SessionMemory;
use App\Entity\SessionMemoryRevision;
use App\Session\SessionState;

/**
 * The session page's view model (docs/design/SESSION_TASKS.md §6.1, §8,
 * build order step 6): the derived state and what a resume would do, the
 * lifecycle controls the state honestly allows, and the memory — the
 * objective, the notes by tier, and each entry's revision history.
 *
 * Everything here is derived at read time, like the state itself: the page
 * cannot show a memory the block would not render, because both come from
 * the same repository rows.
 */
final readonly class SessionView
{
    /**
     * @param list<SessionMemory>                     $hotNotes  newest first
     * @param list<SessionMemory>                     $coldNotes newest first
     * @param array<int, list<SessionMemoryRevision>> $revisions keyed by memory id, newest first
     */
    public function __construct(
        public SessionState $state,
        public SessionState $underlying,
        public ?Run $lastSlice,
        public string $explanation,
        public ?SessionMemory $objective,
        public array $hotNotes,
        public array $coldNotes,
        public array $revisions,
    ) {
    }

    /**
     * Whether a launch control may be offered: the states whose next act is
     * "launch a slice" — start (`fresh`), resume (`owed` / `blocked` /
     * `stopped`). A paused session offers it too when enabling would land in
     * one of those states; the controller's start action enables first, so
     * one control serves start and resume alike (§8: "resume... re-enables
     * if paused").
     */
    public function startable(): bool
    {
        return \in_array($this->underlying, [
            SessionState::Fresh,
            SessionState::Owed,
            SessionState::Retrying,
            SessionState::Blocked,
            SessionState::Stopped,
        ], true);
    }

    /**
     * Whether Pause may be offered: enabled, with something a pause would
     * actually stop — a slice in flight, or a next slice the session would
     * otherwise launch (owed). Pausing a fresh, blocked, stopped or
     * complete session would stop nothing.
     */
    public function pausable(): bool
    {
        return SessionState::Paused !== $this->state
            && \in_array($this->underlying, [SessionState::Working, SessionState::Owed], true);
    }

    /** The launch control's label: a first slice is started, anything else is resumed. */
    public function startLabel(): string
    {
        return SessionState::Fresh === $this->underlying ? 'Start session' : 'Resume session';
    }

    /**
     * The paused-mid-slice case: re-enabling is a pure unpause — the
     * in-flight slice finishes on its own, and there is nothing to launch,
     * so the control is the plain enable, not a launch.
     */
    public function enableOnly(): bool
    {
        return SessionState::Paused === $this->state && SessionState::Working === $this->underlying;
    }

    /** The state as a human reads it — `underlying` first when paused, so the page never hides what a resume would do. */
    public function stateLabel(): string
    {
        return SessionState::Paused === $this->state
            ? \sprintf('paused (%s)', $this->underlying->value)
            : $this->state->value;
    }

    /**
     * The revision history of one memory row, newest first.
     *
     * @return list<SessionMemoryRevision>
     */
    public function revisionsOf(SessionMemory $memory): array
    {
        $id = $memory->getId();

        return null === $id ? [] : ($this->revisions[$id] ?? []);
    }
}
