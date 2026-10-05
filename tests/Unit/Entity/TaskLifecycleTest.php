<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §4.4 semantics: drafts are editable, enabled tasks are immutable,
 * replacement/approve/reject chains behave as specified.
 */
final class TaskLifecycleTest extends TestCase
{
    private function makeTask(): Task
    {
        return new Task(
            'Morning Briefing',
            'Compose the briefing.',
            TaskKind::Run,
            ToolboxMode::Tags,
            ['weather', 'calendar'],
            TaskAuthor::User,
        );
    }

    public function testDraftTaskIsEditable(): void
    {
        $task = $this->makeTask();

        self::assertTrue($task->isDraft());
        $task->setTitle('Renamed');
        $task->setBrief('New brief');
        $task->setToolbox(['news']);

        self::assertSame('Renamed', $task->getTitle());
        self::assertSame('New brief', $task->getBrief());
        self::assertSame(['news'], $task->getToolbox());
    }

    public function testEnabledTaskIsImmutable(): void
    {
        $task = $this->makeTask();
        $task->enable();

        self::assertTrue($task->isEnabled());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $task->setTitle('Sneaky mutation');
    }

    /**
     * Disable is the pause (SPEC §4.4): a lifecycle flag an enabled task is
     * allowed to receive, and the one path back to a non-running task that
     * keeps the task itself (content, schedule, history) intact.
     */
    public function testAnEnabledTaskCanBeDisabledAndReEnabled(): void
    {
        $task = $this->makeTask();
        $task->setSchedule('0 8 * * *');
        $task->enable();

        $task->disable();
        self::assertFalse($task->isEnabled());
        self::assertTrue($task->isDraft(), 'a disabled task that never ran is editable again');
        self::assertSame('0 8 * * *', $task->getSchedule(), 'the pause keeps the schedule');

        // Content setter is allowed while disabled — for a task with no runs,
        // the same rule as a draft.
        $task->setTitle('Paused and edited');
        self::assertSame('Paused and edited', $task->getTitle());

        $task->enable();
        self::assertTrue($task->isEnabled(), 're-enabling resumes the same task');
    }

    /**
     * The other side of that coin, and the correction to it (SPEC §4.4): a
     * paused task that has run is still a record. Disable is a lifecycle
     * flag; it is not the editability switch, and it never was — a run makes
     * the version immutable (its step rows are what run.step_id points at,
     * and this project has the incident to prove it).
     */
    public function testADisabledTaskThatHasRunIsNotADraftAndCannotBeMutated(): void
    {
        $task = $this->makeTask();
        $task->enable();
        $task->disable();

        $task->markHasRuns();

        self::assertFalse($task->isEnabled());
        self::assertFalse($task->isDraft(), 'a disabled task with run history is not a draft');
        self::assertTrue($task->isContentLocked());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $task->setTitle('Sneaky mutation of a record');
    }

    /**
     * PendingReplacement is the approval moment (SPEC §4.4) — and an approved
     * replacement is not in it, even after it has been paused. This is the
     * predicate the UI's approve/reject buttons and reject() both read.
     */
    public function testPendingReplacementIsOnlyTheUndecidedDraft(): void
    {
        $original = $this->makeTask();
        $original->enable();

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        self::assertTrue($draft->isPendingReplacement(), 'undecided: awaiting approve or reject');

        $draft->reject();
        self::assertFalse($draft->isPendingReplacement(), 'a rejected draft is decided (archived)');

        $approved = $original->createReplacementDraft(TaskAuthor::Agent);
        $approved->approve();
        self::assertFalse($approved->isPendingReplacement(), 'an approved replacement is the live task');

        // …and pausing the live task does not reopen its approval moment.
        $approved->disable();
        self::assertFalse($approved->isPendingReplacement(), 'a paused task is decided, not pending');
        self::assertFalse($original->isPendingReplacement(), 'a superseded original is not pending either');
    }

    public function testEnableIsRejectedOnArchivedTask(): void
    {
        $task = $this->makeTask();
        $task->enable();
        $task->disable();
        $task->createReplacementDraft(TaskAuthor::Agent);

        // simulate archived original via approval path
        $original = $this->makeTask();
        $original->enable();
        $replacement = $original->createReplacementDraft(TaskAuthor::Agent);
        $replacement->approve();

        self::assertTrue($original->isArchived());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('superseded');

        $original->enable();
    }

    public function testReplacementDraftCarriesContentAndChain(): void
    {
        $original = $this->makeTask();
        $original->enable();

        $draft = $original->createReplacementDraft(TaskAuthor::Agent);

        self::assertFalse($draft->isEnabled(), 'agent-authored replacement lands disabled (SPEC §4.3)');
        self::assertSame($original, $draft->getReplacementFor());
        self::assertSame('Morning Briefing', $draft->getTitle());
        self::assertSame(TaskAuthor::Agent, $draft->getCreatedBy());
        self::assertSame(['weather', 'calendar'], $draft->getToolbox());
        // original untouched while draft is pending
        self::assertFalse($original->isSuperseded());
        self::assertTrue($original->isEnabled());
    }

    public function testApproveSwapsAtomically(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->setBrief('Tightened brief');
        $draft->approve();

        self::assertTrue($draft->isEnabled(), 'approved replacement becomes enabled');
        self::assertTrue($original->isArchived());
        self::assertSame($draft, $original->getSupersededBy());
        self::assertFalse($original->isEnabled());
    }

    public function testApproveRejectedWhenOriginalAlreadySuperseded(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $first = $original->createReplacementDraft(TaskAuthor::Agent);
        $first->approve();
        $second = $original->createReplacementDraft(TaskAuthor::Agent);

        self::assertFalse(false === $original->isSuperseded());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already superseded');

        $second->approve();
    }

    public function testRejectArchivesDraftOnly(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->reject();

        self::assertTrue($draft->isArchived());
        self::assertFalse($draft->isEnabled());
        self::assertFalse($original->isArchived());
        self::assertTrue($original->isEnabled());
    }

    /**
     * An approved replacement is enabled and its swap is done, so approving
     * it again must fail rather than re-run the swap against a stale original.
     */
    public function testApproveRejectedWhenAlreadyApproved(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->approve();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already-approved');

        $draft->approve();
    }

    /**
     * Reject is the destructive answer to a *proposal*. An approved
     * replacement is a live task; rejecting it would archive the task the
     * swap just made runnable. (Post-swap, the entity still carries its
     * replacementFor pointer for the record's history, which is why the
     * enabled guard is what has to catch this.).
     */
    public function testRejectRejectedWhenAlreadyApproved(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->approve();

        try {
            $draft->reject();
            self::fail('Rejecting an approved replacement must be refused.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Cannot reject an enabled task', $e->getMessage());
        }

        self::assertTrue($draft->isEnabled(), 'the live task stays enabled');
        self::assertFalse($draft->isArchived(), 'the live task is not archived by a stale reject');
    }

    /**
     * The stale-reject guard, reached by disabling first. Disabling an
     * approved replacement is now legal (it is the pause), so an approved-but-
     * paused task no longer trips the `enabled` guard — the superseded-original
     * guard is what must catch it, or a stale POST would archive a live
     * (paused) task.
     */
    public function testRejectRejectedWhenTheApprovedReplacementWasDisabled(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->approve();
        $draft->disable();

        try {
            $draft->reject();
            self::fail('Rejecting a paused, already-approved replacement must be refused.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('already superseded', $e->getMessage());
        }

        self::assertFalse($draft->isArchived(), 'a stale reject must not archive a paused live task');
    }

    /**
     * A rejected draft is already a dead record; a second reject is a
     * no-op-shaped request the entity refuses, not a second archive.
     */
    public function testRejectRejectedWhenAlreadyArchived(): void
    {
        $original = $this->makeTask();
        $original->enable();
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $draft->reject();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('archived');

        $draft->reject();
    }

    /**
     * A plain draft — no replacementFor — still cannot be rejected: reject is
     * a replacement decision, and discard is the action for a stray draft.
     */
    public function testRejectRequiresAReplacement(): void
    {
        $task = $this->makeTask();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not a replacement draft');

        $task->reject();
    }
}
