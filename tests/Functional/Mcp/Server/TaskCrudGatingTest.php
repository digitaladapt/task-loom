<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp\Server;

use App\Entity\Run;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Repository\StepRepository;
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

    public function testUpdateSeesOutOfBandEnable(): void
    {
        // Regression (live incident): a long-lived serve process had already
        // loaded a task into its identity map when the user flipped
        // enabled=1 directly in the DB. findOrThrow() returned the cached
        // entity (enabled=false), the update treated it as a draft, and the
        // toolbox edit landed on the enabled row — the write gate was
        // bypassed. findOrThrow must refresh so the gate reads the DB truth.
        $task = $this->crud->create('Orig', 'B', TaskKind::Run, ToolboxMode::Tags, ['a'], null);

        // Simulate the out-of-band DB flip: the row changes, but the
        // already-managed entity in this process is stale.
        $this->em()->getConnection()->executeStatement(
            'UPDATE task SET enabled = 1 WHERE id = ?',
            [$task->getId()],
        );

        $draft = $this->crud->update($task->getId(), ['title' => 'Should Not Touch Original']);

        // SPEC §4.4: the enabled original must be untouched; the update must
        // produce a disabled replacement draft instead.
        $this->em()->refresh($task);
        self::assertTrue($task->isEnabled());
        self::assertSame('Orig', $task->getTitle());
        self::assertNotSame($task->getId(), $draft->getId());
        self::assertFalse($draft->isEnabled());
        self::assertSame($task->getId(), $draft->getReplacementFor()?->getId());
        self::assertSame('Should Not Touch Original', $draft->getTitle());
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

    /**
     * Regression (live incident): a task that had been enabled, then disabled
     * because it misbehaved, was updated to fix it. The gate asked only
     * `isEnabled()`, the disabled task looked like an editable draft, and the
     * in-place branch deleted its step rows — including the one a run of the
     * task still pointed at. The task's own page then could not be loaded at
     * all ("Entity of type 'App\\Entity\\Step' for IDs id(71) was not
     * found"): the run surface pulls the runs by task_id, and one dangling
     * step reference is fatal to the whole render.
     *
     * The rule is that a version which has run is a record, so the edit must
     * land as a replacement draft — and the step rows the ledger points at
     * must survive it.
     */
    public function testUpdateOnADisabledTaskThatHasRunCreatesAReplacementAndKeepsTheStepRows(): void
    {
        $task = $this->crud->create('Stepped', 'Compose.', TaskKind::Run, ToolboxMode::Tags, ['a'], null, [
            [['title' => 'Fetch', 'brief' => 'Fetch it.']],
        ]);
        $task->enable();
        $this->tasks->save($task);

        $steps = static::getContainer()->get(StepRepository::class)->findForTask($task);
        self::assertCount(1, $steps);
        $stepIds = array_map(static fn ($step) => $step->getId(), $steps);

        // The run that ties the ledger to those step rows: a step child,
        // which is the shape that carries run.step_id.
        $run = new Run($task);
        $run->setRole(RunRole::Step);
        $run->setStep($steps[0]);
        $run->setStatus(RunStatus::Succeeded);
        $this->em()->persist($run);
        $this->em()->flush();

        // The incident's shape: enabled, broke, disabled, then fixed.
        $task->disable();
        $this->tasks->save($task);
        self::assertFalse($task->isEnabled());

        $draft = $this->crud->update($task->getId(), [
            'brief' => 'Fixed brief',
            'steps' => [[['title' => 'Fetch retry', 'brief' => 'Fetch it again.']]],
        ]);

        // A replacement, not an in-place edit.
        self::assertNotSame($task->getId(), $draft->getId());
        self::assertSame($task->getId(), $draft->getReplacementFor()?->getId());
        self::assertSame('Fixed brief', $draft->getBrief());

        // The original is untouched, and its step rows — the ones the run
        // points at — are still there. This is the half that used to crash.
        $this->em()->clear();
        $original = $this->tasks->find($task->getId());
        self::assertNotNull($original);
        self::assertSame('Compose.', $original->getBrief());

        $survivors = static::getContainer()->get(StepRepository::class)->findForTask($original);
        self::assertSame($stepIds, array_map(static fn ($step) => $step->getId(), $survivors));

        // And the run still resolves its step — this is the assertion that
        // used to be impossible. Loading the run surface is what threw the
        // incident's "Entity of type 'App\Entity\Step' for IDs id(71) was
        // not found"; a dangling step_id is fatal to the whole render.
        $reloaded = $this->em()->find(Run::class, $run->getId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getStep(), 'the run keeps the step it recorded');
        self::assertSame($stepIds[0], $reloaded->getStep()->getId());
    }

    /**
     * The mutability line the incident drew: a draft is edited in place only
     * while it has never run. Same gate, both sides, so the two cannot drift
     * apart again.
     */
    public function testOnlyANeverRunNeverEnabledDraftIsEditedInPlace(): void
    {
        $neverRun = $this->crud->create('Never ran', 'B', TaskKind::Run, ToolboxMode::Tags, [], null);
        self::assertTrue($neverRun->isDraft());

        $edited = $this->crud->update($neverRun->getId(), ['title' => 'Renamed in place']);
        self::assertSame($neverRun->getId(), $edited->getId());
        self::assertSame('Renamed in place', $edited->getTitle());

        $ran = $this->crud->create('Ran', 'B', TaskKind::Run, ToolboxMode::Tags, [], null);
        $run = new Run($ran);
        $this->em()->persist($run);
        $this->em()->flush();

        // Never enabled, but a run exists: a record, edited by replacement.
        self::assertFalse($ran->isEnabled());
        $replacement = $this->crud->update($ran->getId(), ['title' => 'Renamed by replacement']);
        self::assertNotSame($ran->getId(), $replacement->getId());
        self::assertSame('Ran', $this->tasks->find($ran->getId())?->getTitle(), 'the run-bearing task is never mutated');
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
