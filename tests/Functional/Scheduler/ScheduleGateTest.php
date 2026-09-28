<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduler;

use App\Admin\TaskAdminService;
use App\Admin\TaskLifecycleException;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Scheduler\ScheduleFormatException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The schedule enforcement gate (SPEC §14): an invalid cron expression
 * never becomes an enabled task — and never persists through the authoring
 * boundary. Same discipline as the step graph (§13.2).
 */
final class ScheduleGateTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskAdminService $admin; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskCrud $crud; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\\Entity\\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Run')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Step')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->admin = new TaskAdminService(
            $container->get(\App\Repository\TaskRepository::class),
            $container->get(\App\Repository\StepRepository::class),
            $container->get(\App\StepModel\StepGraphValidator::class),
            new \App\Scheduler\ScheduleExpression(),
            $this->em,
        );

        $this->crud = new TaskCrud(
            $container->get(\App\Repository\TaskRepository::class),
            $container->get(\App\Repository\StepRepository::class),
            $container->get(\App\StepModel\StepGraphCodec::class),
            $container->get(\App\StepModel\StepGraphValidator::class),
            new \App\Scheduler\ScheduleExpression(),
            $this->em,
        );
    }

    public function testEnableRefusesAnInvalidScheduleAndLeavesTheRowUntouched(): void
    {
        $task = $this->task('Bad cron', 'not a cron');
        $taskId = (int) $task->getId();

        try {
            $this->admin->enableTask($taskId);
            self::fail('an invalid schedule must never become an enabled task');
        } catch (TaskLifecycleException $e) {
            self::assertStringContainsString('Cannot enable task', $e->getMessage());
            self::assertStringContainsString('not a cron', $e->getMessage());
        }

        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $reloaded);
        self::assertFalse($reloaded->isEnabled(), 'the enable was refused before any row moved');
    }

    public function testEnableAcceptsAValidSchedule(): void
    {
        $task = $this->task('Good cron', '0 8 * * 1-5');
        $enabled = $this->admin->enableTask((int) $task->getId());

        self::assertTrue($enabled->isEnabled());
        self::assertNull($enabled->getNextRunAt(), 'enabling validates; the tick owns arming');
    }

    public function testApproveRefusesAnInvalidSchedule(): void
    {
        $original = $this->task('Original', '0 8 * * *');
        $this->admin->enableTask((int) $original->getId());

        $draft = $original->createReplacementDraft(TaskAuthor::User);
        $draft->setSchedule('nonsense');
        $this->em->persist($draft);
        $this->em->flush();

        try {
            $this->admin->approveTask((int) $draft->getId());
            self::fail('an invalid schedule must never be approved into a running task');
        } catch (TaskLifecycleException $e) {
            self::assertStringContainsString('Cannot approve task', $e->getMessage());
        }

        $this->em->clear();
        $reloadedDraft = $this->em->find(Task::class, $draft->getId());
        self::assertInstanceOf(Task::class, $reloadedDraft);
        self::assertFalse($reloadedDraft->isEnabled());
    }

    public function testCreateViaCrudRefusesAnInvalidScheduleBeforeAnyWrite(): void
    {
        try {
            $this->crud->create('Agent task', 'Do it.', TaskKind::Run, ToolboxMode::Explicit, ['x'], 'every so often');
            self::fail('authoring must refuse an invalid schedule');
        } catch (ScheduleFormatException $e) {
            self::assertStringContainsString('every so often', $e->getMessage());
        }

        // Nothing persisted — the refusal happened before the write.
        $count = $this->em->createQuery('SELECT COUNT(t.id) FROM App\\Entity\\Task t')->getSingleScalarResult();
        self::assertSame(0, (int) $count);
    }

    public function testUpdateViaCrudRefusesAnInvalidSchedule(): void
    {
        $task = $this->task('Editable', '0 8 * * *');

        $this->expectException(ScheduleFormatException::class);
        $this->crud->update((int) $task->getId(), ['schedule' => 'not valid']);
    }

    public function testUpdateNormalizesBlankToNoSchedule(): void
    {
        $task = $this->task('Editable', '0 8 * * *');

        $updated = $this->crud->update((int) $task->getId(), ['schedule' => '   ']);

        self::assertNull($updated->getSchedule(), 'blank clears the schedule rather than storing whitespace');
    }

    public function testReplacementDraftDoesNotCopyTheCursor(): void
    {
        // The cursor is scheduling state, not task content (§14): a
        // replacement starts unarmed and is armed fresh by the tick.
        $original = $this->task('Original', '0 8 * * *');
        $this->admin->enableTask((int) $original->getId());

        // Simulate an armed cursor.
        $this->em->createQuery('UPDATE App\\Entity\\Task t SET t.nextRunAt = :c WHERE t.id = :id')
            ->setParameter('c', 1234567890)
            ->setParameter('id', $original->getId())
            ->execute();
        $this->em->clear();

        $original = $this->em->find(Task::class, $original->getId());
        self::assertInstanceOf(Task::class, $original);
        $draft = $original->createReplacementDraft(TaskAuthor::User);
        $this->em->persist($draft);
        $this->em->flush();

        self::assertNull($draft->getNextRunAt() ?? null, 'a replacement draft carries the schedule, not the cursor');
        self::assertSame('0 8 * * *', $draft->getSchedule());
    }

    private function task(string $title, ?string $schedule): Task
    {
        $task = new Task($title, 'Do the thing.', TaskKind::Run, ToolboxMode::Explicit, ['x'], TaskAuthor::User);
        $task->setSchedule($schedule);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }
}
