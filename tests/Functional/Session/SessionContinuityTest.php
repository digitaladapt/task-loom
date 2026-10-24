<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Admin\TaskAdminService;
use App\Entity\Run;
use App\Entity\SessionDeclarationOutcome;
use App\Entity\SessionMemory;
use App\Entity\SessionMemorySource;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Repository\RunRepository;
use App\Repository\SessionMemoryRepository;
use App\Repository\TaskRepository;
use App\Scheduler\ScheduleExpression;
use App\Session\SessionMemoryStore;
use App\Session\SessionSliceEngine;
use App\Session\SessionState;
use App\StepModel\StepGraphCodec;
use App\StepModel\StepGraphValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Continuity across an approved replacement (docs/design/SESSION_TASKS.md §8.2).
 *
 * A session is not a task row: approving an edit swaps in a new row (SPEC
 * §4.4), and everything that makes the session *one* session has to survive
 * that swap. This covers the three things the swap used to drop silently —
 * the memory store, the `hasRuns` standing, and the run history the state is
 * derived from — because each of them fails in a different way and only the
 * end-to-end assertion catches all three at once.
 *
 * The bug being closed: `session_memory.task_id` cascades, so an approval
 * that carried nothing left the successor with an empty store, a `fresh`
 * state, and its predecessor's history deleted on the next cleanup.
 */
final class SessionContinuityTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskCrud $crud; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskAdminService $admin; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryStore $memory; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryRepository $memories; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionSliceEngine $engine; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private RunRepository $runs; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskRepository $tasks; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemoryRevision')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemory')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StreamEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->tasks = $container->get(TaskRepository::class);
        $this->memories = $container->get(SessionMemoryRepository::class);
        $this->runs = $container->get(RunRepository::class);
        $this->memory = $container->get(SessionMemoryStore::class);
        $this->engine = $container->get(SessionSliceEngine::class);

        $this->admin = new TaskAdminService(
            $this->tasks,
            $container->get(\App\Repository\StepRepository::class),
            $container->get(StepGraphValidator::class),
            new ScheduleExpression(),
            $this->em,
            $this->memory,
        );

        $this->crud = new TaskCrud(
            $this->tasks,
            $container->get(\App\Repository\StepRepository::class),
            $container->get(StepGraphCodec::class),
            $container->get(StepGraphValidator::class),
            new ScheduleExpression(),
            $this->em,
        );
    }

    public function testApprovingAReplacementCarriesTheWholeMemoryStore(): void
    {
        $original = $this->enabledSession();
        $originalObjective = $this->memory->setObjective($original, 'Ship the importer.', SessionMemorySource::Operator);
        $this->memory->addNote($original, 'Half way through the retry pass.', SessionMemorySource::Session);
        $pinned = $this->memory->addNote($original, 'Never touch the live table.', SessionMemorySource::Operator);
        $this->memory->setPinned($pinned, true);
        $this->memory->editMemory($originalObjective, 'Ship the importer, with the safety rail.', SessionMemorySource::Operator);
        $revisionCount = \count($this->memories->findRevisionsForMemory($originalObjective));

        $draft = $this->replacementDraft($original, 'Ship the importer, carefully.');
        $this->reload($original);

        $this->admin->approveTask((int) $draft->getId());

        $reloadedDraft = $this->reload($draft);
        $carriedObjective = $this->memories->findObjectiveFor((int) $reloadedDraft->getId());

        self::assertNotNull($carriedObjective, 'the successor inherits the session objective — an approval must not empty the store');
        self::assertSame((int) $originalObjective->getId(), (int) $carriedObjective->getId(), 'the objective is MOVED, not copied: one row, still "the" objective');
        self::assertSame('Ship the importer, with the safety rail.', $carriedObjective->getText(), 'the carried objective is the text as it stood, not the draft brief');
        self::assertSame([], $this->memories->findAllFor((int) $this->reload($original)->getId()), 'nothing is left on the superseded version — a second store would be a second truth');

        $notes = $this->memories->findNotesFor((int) $reloadedDraft->getId());
        self::assertCount(2, $notes, 'notes travel with the objective');
        self::assertTrue($this->noteByText($reloadedDraft, 'Never touch the live table.')->isPinned(), 'a pin set by the operator is still a pin after an approval');
        self::assertFalse($this->noteByText($reloadedDraft, 'Half way through the retry pass.')->isPinned(), 'and an unpinned note is still unpinned');
        self::assertSame(
            $revisionCount,
            \count($this->memories->findRevisionsForMemory($carriedObjective)),
            'the superseded texts come along — steering stays inspectable across a replacement',
        );
    }

    public function testCarryLeavesASuccessorThatAlreadyHasMemoryAlone(): void
    {
        $original = $this->enabledSession();
        $this->memory->setObjective($original, 'Original aim.', SessionMemorySource::Operator);

        $draft = $this->replacementDraft($original, 'Edited aim.');
        $this->memory->setObjective($draft, 'Written to the draft directly.', SessionMemorySource::Session);

        $this->admin->approveTask((int) $draft->getId());

        $objective = $this->memories->findObjectiveFor((int) $this->reload($draft)->getId());

        self::assertNotNull($objective);
        self::assertSame(
            'Written to the draft directly.',
            $objective->getText(),
            'two stores are never merged — the successor keeps its own and the original keeps what a human can still read',
        );
        self::assertNotNull(
            $this->memories->findObjectiveFor((int) $this->reload($original)->getId()),
            'the untouched original does not lose its row',
        );
    }

    public function testStateOfAnApprovedSuccessorComesFromTheChainsRunsNotAFreshTaskRow(): void
    {
        $original = $this->enabledSession();

        // One settled slice that declared a milestone: the session is owed the
        // next slice. That is the whole thing the swap must not forget.
        $this->slice($original, SessionDeclarationOutcome::Milestone);

        self::assertSame(SessionState::Owed, $this->engine->state($original));

        $draft = $this->replacementDraft($original, 'Ship the importer, carefully.');
        $this->reload($original);
        $this->admin->approveTask((int) $draft->getId());

        $successor = $this->reload($draft);

        self::assertSame(
            SessionState::Owed,
            $this->engine->state($successor),
            'the continuing session is owed a slice — not `fresh` because its task row is new',
        );
        self::assertNotNull($this->engine->lastSlice($successor), 'the last slice is the original\'s — the session has run');
        // `hasRuns` is a cache of a runs-table read, not a column, so a fresh
        // instance is expected to report false — assert the durable facts
        // instead: the chain has run, and the successor therefore cannot be
        // edited in place.
        self::assertTrue($this->tasks->hasRunsInChain($successor), 'the session has run, and the successor is inside that chain');
        self::assertNotSame(
            $successor->getId(),
            $this->crud->update((int) $successor->getId(), ['brief' => 'An edit after approval.'], TaskAuthor::User)->getId(),
            'an edit to an approved successor is a replacement draft — its content stayed locked',
        );
    }

    public function testAFreshSessionStaysFreshWhenItIsReplaced(): void
    {
        $original = $this->enabledSession();
        $draft = $this->replacementDraft($original, 'Never ran.');
        $this->reload($original);

        $this->admin->approveTask((int) $draft->getId());
        $successor = $this->reload($draft);

        self::assertSame(SessionState::Fresh, $this->engine->state($successor), 'nothing has happened to this session yet');
        self::assertFalse($successor->hasRuns(), 'a chain that never ran is not a record');
    }

    public function testTheChainWalkStopsAtTheTaskItself(): void
    {
        $task = $this->enabledSession();
        $this->slice($task, SessionDeclarationOutcome::Milestone);

        self::assertCount(1, $this->runs->findForChain($task));
        self::assertCount(1, $this->runs->findForTask($task), 'an unchained task behaves exactly as before');
    }

    private function enabledSession(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /**
     * A replacement draft, created the way the write gate creates one — the
     * same path an operator edit takes (SPEC §4.4).
     */
    private function replacementDraft(Task $original, string $brief): Task
    {
        $draft = $this->crud->update((int) $original->getId(), ['brief' => $brief], TaskAuthor::User);

        self::assertNotSame($original->getId(), $draft->getId(), 'a run task is replaced, never mutated');

        return $draft;
    }

    private function slice(Task $task, SessionDeclarationOutcome $outcome): Run
    {
        $run = new Run($task);
        $run->markStarted();
        $run->recordDeclaration($outcome, 'A slice settled.');
        $run->markSucceeded();

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function noteByText(Task $task, string $text): SessionMemory
    {
        foreach ($this->memories->findNotesFor((int) $task->getId()) as $note) {
            if ($text === $note->getText()) {
                return $note;
            }
        }

        self::fail(\sprintf('No carried note reading "%s".', $text));
    }

    private function reload(Task $task): Task
    {
        $this->em->clear();

        return $this->tasks->find((int) $task->getId()) ?? self::fail('The task disappeared.');
    }
}
