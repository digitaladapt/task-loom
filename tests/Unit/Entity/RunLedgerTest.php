<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Entity\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * Run and ledger semantics (SPEC §5.3, §5.4): typed events, seq ordering,
 * terminal transitions with named errors.
 */
final class RunLedgerTest extends TestCase
{
    private function makeRun(): Run
    {
        $task = new Task(
            'Morning Briefing',
            'Compose the briefing.',
            TaskKind::Run,
            ToolboxMode::Tags,
            ['weather'],
            TaskAuthor::User,
        );
        $task->enable();

        return new Run($task);
    }

    public function testRunStartsQueuedAndTransitions(): void
    {
        $run = $this->makeRun();

        self::assertSame(RunStatus::Queued, $run->getStatus());
        $run->markStarted();
        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertNotNull($run->getStartedAt());
        self::assertTrue($run->isActive());
    }

    public function testTerminalTransitionsSetFinishedAt(): void
    {
        $run = $this->makeRun();
        $run->markStarted();

        $run->markSucceeded();
        self::assertNotNull($run->getFinishedAt());
        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertFalse($run->isActive());
    }

    public function testAppendEventAssignsSeq(): void
    {
        $run = $this->makeRun();

        $first = $run->appendEvent(new RunEvent(RunEventType::LlmRequest));
        $second = $run->appendEvent(new RunEvent(RunEventType::LlmResponse));
        $third = $run->appendEvent(new RunEvent(RunEventType::ToolCall));

        self::assertSame(1, $first->getSeq());
        self::assertSame(2, $second->getSeq());
        self::assertSame(3, $third->getSeq());
        self::assertSame($run, $first->getRun());
    }

    public function testToolCallAttachesToRunEvent(): void
    {
        $run = $this->makeRun();
        $event = $run->appendEvent(new RunEvent(RunEventType::ToolCall));

        $call = new ToolCall('get_weather', 'weather', ['location' => 'Berlin']);
        $event->attachToolCall($call);

        self::assertSame($event, $call->getRunEvent());
        self::assertCount(1, $event->getToolCalls());
        self::assertSame(['location' => 'Berlin'], $call->getArguments());
    }

    public function testStepBudgetAndCheckpoint(): void
    {
        $run = $this->makeRun();
        $run->incrementStepCount();
        $run->incrementStepCount();
        $run->setCheckpoint(['last_seq' => 4, 'window' => []]);

        self::assertSame(2, $run->getStepCount());
        self::assertSame(['last_seq' => 4, 'window' => []], $run->getCheckpoint());
    }

    public function testTerminalErrorClassRollupData(): void
    {
        $run = $this->makeRun();
        $run->markFailed(ErrorClass::LlmError);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(ErrorClass::LlmError, $run->getErrorClass());
    }
}
