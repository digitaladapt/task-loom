<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\SessionMemory;
use App\Entity\SessionMemoryKind;
use App\Entity\SessionMemoryRevision;
use App\Entity\SessionMemorySource;
use App\Entity\SessionMemoryTier;
use App\Entity\Task;
use App\Entity\TaskKind;
use App\Repository\SessionMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The session memory store (docs/design/SESSION_TASKS.md §3): the single
 * writer of a session's memory, and the keeper of its caps.
 *
 * **One writer.** Both the harness tools (step 3) and the UI (step 6) go
 * through here — the same discipline that makes `TaskCrud` the task write
 * gate. That is what makes "the objective is one row per session" a property
 * of the code rather than a hope about callers, and it is asserted by a test.
 *
 * **The session is the namespace** (§3.1): every operation is scoped by the
 * session's task id alone. There is no key — a key namespaces a store that
 * serves many topics; this store serves one session, so a key would be a
 * second dimension with no consumer. Writes for a `run`-kind task are refused
 * outright: memory belongs to a session, and writing it anywhere else is a
 * programming error, not a case to handle quietly.
 *
 * **The store is bounded on three axes here** (§3.3) — notes injected, notes
 * retained, and one write's size — with the fourth (the rendered block's own
 * percentage) enforced where the block is rendered
 * ({@see SessionMemoryRenderer}, backed by
 * {@see \App\Context\ContextWindow::sessionMemoryBudgetChars()}). The write
 * cap is deliberately a store concern rather than a tool concern: the count
 * caps bound how many notes exist, but not how large one write is, and a
 * single unbounded write is as bad for the singleton objective as for a
 * note.
 *
 * Ageing: writes go to hot; once the hot set is over the cap the oldest
 * unpinned notes are demoted (oldest first); beyond the cold cap the oldest
 * cold notes are dropped. A pinned note is skipped by ageing — the operator
 * keeps it hot explicitly — and if pins alone overflow the hot cap that is
 * the operator's explicit choice: the percentage backstop still bounds the
 * request (§3.2). Ageing never touches the objective: it is not a note, not
 * in the tier system, not droppable — which is the whole reason it is a
 * separate shape.
 */
final readonly class SessionMemoryStore
{
    public function __construct(
        private EntityManagerInterface $em,
        private SessionMemoryRepository $memories,
        private int $hotCap = 5,
        private int $coldCap = 25,
        private int $writeMaxChars = 2000,
    ) {
        if ($hotCap < 0 || $coldCap < 0) {
            throw new \LogicException('Session memory caps must be zero or greater (TASKLOOM_SESSION_HOT / TASKLOOM_SESSION_COLD).');
        }
        if ($writeMaxChars < 1) {
            throw new \LogicException('TASKLOOM_SESSION_WRITE_MAX_CHARS must be at least 1 — a cap of zero would refuse every write.');
        }
    }

    /**
     * Set the session's objective — create it, or replace the existing one
     * (§3.2: change by replacement; last writer wins, visibly).
     *
     * The superseded text is appended to the revision history (with its
     * source) before the replacement, so a steer is always reversible and
     * the block's "who set it" line can never be silently rewritten.
     */
    public function setObjective(Task $task, string $text, SessionMemorySource $source): SessionMemory
    {
        $this->guardWritable($task);
        $text = $this->guardText($text);

        $existing = $this->memories->findObjectiveFor((int) $task->getId());
        if (null === $existing) {
            $objective = new SessionMemory($task, SessionMemoryKind::Objective, $text, $source);
            $this->em->persist($objective);
            $this->em->flush();

            return $objective;
        }

        $this->keepRevision($existing);
        $existing->applyText($text, $source);
        $this->em->flush();

        return $existing;
    }

    /**
     * Append one note, hot (§3.2). Ageing runs immediately after, so the
     * store is never left over any cap it can reconcile.
     */
    public function addNote(Task $task, string $text, SessionMemorySource $source): SessionMemory
    {
        $this->guardWritable($task);
        $text = $this->guardText($text);

        $note = new SessionMemory($task, SessionMemoryKind::Note, $text, $source);
        $this->em->persist($note);
        $this->em->flush();

        $this->age($task);

        return $note;
    }

    /**
     * Replace a memory row's text — the operator's edit (§6.1), the same
     * "replacement, not mutation" as the objective's writes (§6.3).
     *
     * The superseded text is kept in the history first, exactly as
     * setObjective does, so every edit is visible and reversible. Editing a
     * session-written note is also a **provenance change**, and the passed
     * source records that: the text the model will now read is the
     * operator's, and the revision history shows what it replaced.
     */
    public function editMemory(SessionMemory $memory, string $text, SessionMemorySource $source): void
    {
        $this->guardWritable($memory->getTask());
        $text = $this->guardText($text);

        $this->keepRevision($memory);
        $memory->applyText($text, $source);
        $this->em->flush();
    }

    /**
     * Delete one note (§6.1: the operator's delete).
     *
     * The note's revision history goes with it, deliberately — the same
     * reasoning as cold-drop overflow (§3.3): history of a thing that no
     * longer exists would be the unbounded store this table must not be.
     * The objective cannot be deleted — it is always injected (§3.2);
     * clearing it is not a state the design has.
     */
    public function deleteNote(SessionMemory $note): void
    {
        $this->guardWritable($note->getTask());
        if (!$note->isNote()) {
            throw new \LogicException('The objective cannot be deleted — it is always injected; replace it instead.');
        }

        // The history goes through the UnitOfWork, not a bulk DQL delete:
        // a bulk delete bypasses it, leaving any already-managed revision
        // rows dangling in the identity map — and the NEXT flush then trips
        // over their association to the removed note, as a Doctrine error
        // that looks nothing like its cause. Loading a handful of revisions
        // is the cheap side of that trade.
        foreach ($this->memories->findRevisionsForMemory($note) as $revision) {
            $this->em->remove($revision);
        }
        $this->em->remove($note);
        $this->em->flush();
    }

    /**
     * Pin or unpin a note (§6.1). Pinning is one operator act that keeps the
     * note hot — exempt from ageing — and drags it hot if it was cold
     * ({@see SessionMemory::pin()}); unpinning leaves the tier where it is,
     * and the next ageing pass reconciles the cap.
     */
    public function setPinned(SessionMemory $note, bool $pinned): void
    {
        $this->guardWritable($note->getTask());

        if ($pinned) {
            $note->pin();
        } else {
            $note->unpin();
        }
        $this->em->flush();
    }

    /**
     * Move a note between tiers (§6.1): `cold` demotes (the transition
     * ageing applies, made explicit by the operator), `hot` promotes a cold
     * note back. Both are operator acts, so neither silently reconciles the
     * hot cap — {@see SessionMemory::demote()} and
     * {@see SessionMemory::promote()} state the rules, and they are the
     * rules the ageing pass then applies.
     */
    public function setTier(SessionMemory $note, SessionMemoryTier $tier): void
    {
        $this->guardWritable($note->getTask());

        match ($tier) {
            SessionMemoryTier::Cold => $note->demote(),
            SessionMemoryTier::Hot => $note->promote(),
        };
        $this->em->flush();
    }

    /**
     * Revert a memory row to a prior revision (§6.1, §6.3): the revert is
     * itself an edit — it keeps the current text in the history first, then
     * applies the old text, marked as the operator's (they are choosing it
     * now, deliberately). Nothing is ever lost: revert-of-a-revert walks
     * back forward again.
     */
    public function revertToRevision(SessionMemory $memory, SessionMemoryRevision $revision): void
    {
        $this->guardWritable($memory->getTask());
        if ($revision->getMemory() !== $memory) {
            throw new \LogicException('This revision does not belong to that memory row — refusing a cross-memory revert.');
        }

        $this->keepRevision($memory);
        $memory->applyText($revision->getText(), SessionMemorySource::Operator);
        $this->em->flush();
    }

    /**
     * Keep the text an edit replaced (§6.3): one append-only revision row,
     * carrying the revision it belonged to and its provenance.
     */
    private function keepRevision(SessionMemory $memory): void
    {
        $revision = new SessionMemoryRevision(
            $memory,
            $memory->getRevision(),
            $memory->getText(),
            $memory->getSource(),
        );
        $this->em->persist($revision);
    }

    /**
     * Demote hot notes to cold (oldest first) while hot is over its cap,
     * pinned ones skipped; then drop the oldest cold notes while cold is
     * over its cap.
     *
     * The demotion pass is flushed before the cold pass reads: the cold
     * query runs in SQL, so a note that changed hot→cold in this same
     * reconciliation is only a cold candidate after the flush. Without it,
     * the store could leave the cold set over its cap for one write — the
     * exact moment the reconciliation exists to prevent.
     */
    private function age(Task $task): void
    {
        $taskId = (int) $task->getId();

        $hot = $this->memories->findNotesFor($taskId, SessionMemoryTier::Hot);
        $overflow = \count($hot) - $this->hotCap;
        if ($overflow > 0) {
            foreach ($hot as $note) {
                if ($overflow <= 0) {
                    break;
                }
                if ($note->isPinned()) {
                    // Pinned means kept hot — the operator's explicit choice,
                    // and if pins alone overflow the cap the block's own
                    // percentage backstop still bounds the request (§3.2).
                    continue;
                }

                $note->demote();
                --$overflow;
            }

            $this->em->flush();
        }

        $cold = $this->memories->findNotesFor($taskId, SessionMemoryTier::Cold);
        $coldOverflow = \count($cold) - $this->coldCap;
        if ($coldOverflow <= 0) {
            return;
        }

        for ($index = 0; $index < $coldOverflow; ++$index) {
            // The oldest cold note is dropped. A dropped note's revision
            // history goes with it — history of a thing that no longer
            // exists would be the unbounded store this table must not be
            // (§3.3). Removed through the UnitOfWork for the same reason as
            // deleteNote: a bulk DQL delete would leave managed rows
            // dangling in the identity map for the next flush to trip over.
            foreach ($this->memories->findRevisionsForMemory($cold[$index]) as $revision) {
                $this->em->remove($revision);
            }
            $this->em->remove($cold[$index]);
        }

        $this->em->flush();
    }

    /**
     * Memory belongs to a session (§3.1). Writing it for any other kind is
     * refused loudly: the engine only ever injects a session's block, so a
     * row here for a run-kind task would be silently dead — the exact
     * quiet-wrongness this store exists to prevent.
     */
    private function guardWritable(Task $task): void
    {
        if (TaskKind::Session !== $task->getKind()) {
            throw new \LogicException(\sprintf('Session memory belongs to a session; task "%s" is "%s"-kind — nothing would ever inject this row.', $task->getTitle(), $task->getKind()->value));
        }
    }

    /**
     * One write is bounded (§3.3): blank writes are refused (there is no
     * fact in them), and oversized writes are refused with a message naming
     * the knob — the same discipline as the tool-result cap on the other
     * side of the head.
     */
    private function guardText(string $text): string
    {
        $text = trim($text);
        if ('' === $text) {
            throw SessionMemoryWriteException::blank();
        }
        if (\strlen($text) > $this->writeMaxChars) {
            throw SessionMemoryWriteException::tooLong(\strlen($text), $this->writeMaxChars);
        }

        return $text;
    }
}
