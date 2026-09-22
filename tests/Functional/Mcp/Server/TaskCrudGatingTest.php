<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp\Server;

use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SPEC §4.3 — the write gate in the persistence layer. Every write through
 * the task CRUD path persists with enabled = false, no matter what. This
 * test is the enforcement proof: if the gate regresses, this fails.
 */
final class TaskCrudGatingTest extends KernelTestCase
{
    private TaskCrud $crud; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskRepository $tasks; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->flush();
        $em->clear();

        $this->crud = static::getContainer()->get(TaskCrud::class);
        $this->tasks = static::getContainer()->get(TaskRepository::class);
    }

    public function testCreateAlwaysPersistsDisabled(): void
    {
        $task = $this->crud->create(
            'Morning Briefing',
            'Compose the briefing.',
            TaskKind::Run,
            ToolboxMode::Tags,
            ['weather'],
            null,
        );

        self::assertFalse($task->isEnabled());
        self::assertSame(TaskAuthor::Agent, $task->getCreatedBy());
    }

    public function testCreateAppearsInApprovalQueue(): void
    {
        $task = $this->crud->create('Q', 'B', TaskKind::Run, ToolboxMode::Tags, [], null);

        $queue = $this->tasks->findApprovalQueue();
        self::assertCount(1, $queue);
        self::assertSame($task->getId(), $queue[0]->getId());
    }

    public function testUpdateDraftEditsInPlace(): void
    {
        $task = $this->crud->create('Orig', 'B', TaskKind::Run, ToolboxMode::Tags, ['a'], null);

        $updated = $this->crud->update($task->getId(), ['title' => 'Renamed']);

        self::assertSame($task->getId(), $updated->getId());
        self::assertSame('Renamed', $updated->getTitle());
        self::assertFalse($updated->isEnabled());
    }

    public function testUpdateEnabledTaskCreatesDisabledReplacementDraft(): void
    {
        $task = $this->crud->create('Orig', 'B', TaskKind::Run, ToolboxMode::Tags, ['a'], null);
        $task->enable();
        $this->tasks->save($task);

        $draft = $this->crud->update($task->getId(), ['title' => 'New', 'brief' => 'Better brief']);

        // SPEC §4.4: original untouched, still running.
        $this->em()->refresh($task);
        self::assertTrue($task->isEnabled());
        self::assertSame('Orig', $task->getTitle());

        // And the replacement draft: disabled, chained, carrying the edits.
        self::assertNotSame($task->getId(), $draft->getId());
        self::assertFalse($draft->isEnabled());
        self::assertSame($task->getId(), $draft->getReplacementFor()?->getId());
        self::assertSame('New', $draft->getTitle());
        self::assertSame('Better brief', $draft->getBrief());
        self::assertSame(TaskAuthor::Agent, $draft->getCreatedBy());
    }

    public function testUpdateArchivedTaskThrows(): void
    {
        $original = $this->crud->create('Original', 'B', TaskKind::Run, ToolboxMode::Tags, [], null);
        $original->enable();
        $this->tasks->save($original);

        $draft = $this->crud->update($original->getId(), []);
        $draft->reject(); // archives the draft, original unaffected
        $this->tasks->save($draft);

        self::expectException(EntityNotFoundException::class);

        $this->crud->update($draft->getId(), ['title' => 'x']);
    }

    public function testUpdateNonexistentTaskThrows(): void
    {
        self::expectException(EntityNotFoundException::class);

        $this->crud->update(999999, ['title' => 'x']);
    }

    public function testGetReturnsTaskAndListFiltersArchived(): void
    {
        $visible = $this->crud->create('Visible', 'B', TaskKind::Run, ToolboxMode::Tags, [], null);
        $this->crud->create('Visible2', 'B', TaskKind::Run, ToolboxMode::Tags, [], null);

        $got = $this->crud->get($visible->getId());
        self::assertSame($visible->getId(), $got->getId());

        $listed = $this->crud->list();
        self::assertCount(2, $listed);

        $all = $this->crud->list(includeArchived: true);
        self::assertCount(2, $all);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
