<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Run;
use App\Entity\SessionMemoryTier;
use App\Entity\Task;
use App\Repository\RunRepository;
use App\Repository\SessionMemoryRepository;
use App\Session\SessionSliceEngine;
use App\Session\SessionState;

/**
 * Builds the session page's view model (docs/design/SESSION_TASKS.md §6.1,
 * §8, build order step 6). Read-only and total: it renders on any state —
 * fresh, mid-slice, blocked, complete — and derives everything from the same
 * rows the engine and the block read, so the page cannot disagree with what
 * the session will actually do.
 */
final readonly class SessionPresenter
{
    public function __construct(
        private SessionSliceEngine $slices,
        private SessionMemoryRepository $memories,
        private RunRepository $runs,
    ) {
    }

    public function present(Task $task): SessionView
    {
        $taskId = (int) $task->getId();

        $state = $this->slices->state($task);
        $underlying = $this->slices->activeState($task);

        // findForTask: newest first, top-level runs only — a slice IS a
        // top-level run, so runs[0] is the last slice.
        $runs = $this->runs->findForTask($task);
        $lastSlice = $runs[0] ?? null;

        $hot = array_reverse($this->memories->findNotesFor($taskId, SessionMemoryTier::Hot));
        $cold = array_reverse($this->memories->findNotesFor($taskId, SessionMemoryTier::Cold));

        $revisions = [];
        foreach ($this->memories->findRevisionsForTask($taskId) as $revision) {
            $revisions[(int) $revision->getMemory()->getId()][] = $revision;
        }

        return new SessionView(
            state: $state,
            underlying: $underlying,
            lastSlice: $lastSlice,
            explanation: $this->explain($state, $underlying, $lastSlice),
            objective: $this->memories->findObjectiveFor($taskId),
            hotNotes: $hot,
            coldNotes: $cold,
            revisions: $revisions,
        );
    }

    /**
     * One sentence of *why the session is where it is* — the thing the
     * operator's resume decision actually needs. Keyed on the underlying
     * state when paused, so a paused session never hides what a resume
     * would do.
     */
    private function explain(SessionState $state, SessionState $underlying, ?Run $lastSlice): string
    {
        $paused = SessionState::Paused === $state ? 'The session is paused. ' : '';
        $last = null === $lastSlice ? '' : \sprintf(' Last slice: run #%d, %s.', $lastSlice->getId(), $lastSlice->getStatus()->value);

        return $paused.match ($underlying) {
            SessionState::Fresh => 'No slice has run yet — Start launches the first one; it will wait on the session lane behind any waiting chat and any task turn.',
            SessionState::Working => SessionState::Paused === $state
                ? 'Paused mid-slice: the in-flight slice finishes on its own; no next slice launches until you enable it again.'
                : 'A slice is in flight on the session lane; the next starts only if this one declares a milestone.',
            SessionState::Owed => 'The last slice declared a milestone and the next slice is due — it launches automatically; Resume exists to re-launch it if that dispatch was ever lost.',
            SessionState::Complete => 'The last slice declared done — the session is complete.'.
                ' A done session is terminal: the work continues as a new session, or by approving a replacement.',
            SessionState::Blocked => 'The last slice declared blocked — it needs a human. Resume launches the next slice once you have done what it asked for.',
            SessionState::Stopped => 'The last slice settled without a declaration, so the session stopped for a human — no retry happens by itself. Resume launches the next slice.',
            SessionState::Paused => 'The session is paused.',
        }.$last;
    }
}
