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
}
