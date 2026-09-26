<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use PHPUnit\Framework\TestCase;

/**
 * Run role semantics (SPEC §13.3, §13.5): what a run is within the step
 * model, whether it executes turns, whether it is terminal, and how a
 * parent settles from its graph's outcome.
 */
final class RunRoleTest extends TestCase
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

    public function testDefaultRoleIsStandaloneAndExecutesTurns(): void
    {
        $run = $this->makeRun();

        self::assertSame(RunRole::Standalone, $run->getRole());
        self::assertTrue($run->executesTurns());
        self::assertNull($run->getParent());
        self::assertNull($run->getStep());
    }

    public function testParentRoleExecutesNoTurns(): void
    {
        $run = $this->makeRun();
        $run->setRole(RunRole::Parent);

        self::assertFalse($run->executesTurns());
    }

    public function testChildRolesExecuteTurnsAndCarryTheirParent(): void
    {
        $parent = $this->makeRun();
        $parent->setRole(RunRole::Parent);

        foreach ([RunRole::Step, RunRole::FinalConsumer] as $role) {
            $child = $this->makeRun();
            $child->setRole($role);
            $child->setParent($parent);

            self::assertTrue($child->executesTurns());
            self::assertSame($parent, $child->getParent());
        }
    }

    public function testTerminalStates(): void
    {
        $run = $this->makeRun();
        self::assertFalse($run->isTerminal(), 'queued is not terminal');

        $run->markStarted();
        self::assertFalse($run->isTerminal(), 'running is not terminal');

        $succeeded = $this->makeRun();
        $succeeded->markStarted();
        $succeeded->markSucceeded();
        self::assertTrue($succeeded->isTerminal());

        $incomplete = $this->makeRun();
        $incomplete->markStarted();
        $incomplete->markIncomplete();
        self::assertTrue($incomplete->isTerminal());

        $failed = $this->makeRun();
        $failed->markStarted();
        $failed->markFailed(ErrorClass::Unknown);
        self::assertTrue($failed->isTerminal());

        $attention = $this->makeRun();
        $attention->markStarted();
        $attention->markNeedsAttention(ErrorClass::ServerError);
        self::assertTrue($attention->isTerminal());
    }

    public function testParentSettlesWithFailingChildStateAndError(): void
    {
        $parent = $this->makeRun();
        $parent->setRole(RunRole::Parent);
        $parent->markStarted();

        $parent->settleAs(RunStatus::NeedsAttention, ErrorClass::ServerError);

        self::assertSame(RunStatus::NeedsAttention, $parent->getStatus());
        self::assertSame(ErrorClass::ServerError, $parent->getErrorClass());
        self::assertNotNull($parent->getFinishedAt());
        self::assertTrue($parent->isTerminal());
    }

    public function testParentSettlesAsIncompleteWithoutErrorClass(): void
    {
        $parent = $this->makeRun();
        $parent->setRole(RunRole::Parent);
        $parent->markStarted();

        $parent->settleAs(RunStatus::Incomplete);

        self::assertSame(RunStatus::Incomplete, $parent->getStatus());
        self::assertNull($parent->getErrorClass());
    }

    public function testSettleAsRefusesSuccessAndNonTerminalStates(): void
    {
        $parent = $this->makeRun();
        $parent->setRole(RunRole::Parent);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('terminal non-success');

        $parent->settleAs(RunStatus::Succeeded);
    }
}
