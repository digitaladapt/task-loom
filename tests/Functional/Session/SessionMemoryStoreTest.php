<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Entity\SessionMemory;
use App\Entity\SessionMemoryRevision;
use App\Entity\SessionMemorySource;
use App\Entity\SessionMemoryTier;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\SessionMemoryRepository;
use App\Session\SessionMemoryStore;
use App\Session\SessionMemoryWriteException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The session memory store (docs/design/SESSION_TASKS.md §3, build order
 * step 2) against the real database: the objective is a singleton whose
 * replacements keep their superseded text; notes age hot→cold oldest-first
 * with pinned notes skipped and cold overflow dropping the oldest; one
 * write is bounded; and nothing is written for a non-session task.
 *
 * Caps are constructed per-test (small numbers, real repository) so "the
 * third note demotes the first" is asserted literally rather than implied
 * by the defaults.
 */
final class SessionMemoryStoreTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private SessionMemoryRepository $memories; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemoryRevision')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SessionMemory')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->memories = static::getContainer()->get(SessionMemoryRepository::class);
    }

    public function testTheObjectiveIsASingletonAndEveryReplacementKeepsTheOldText(): void
    {
        $store = $this->store();
        $task = $this->sessionTask();

        $first = $store->setObjective($task, 'First aim.', SessionMemorySource::Operator);
        $second = $store->setObjective($task, 'Second aim.', SessionMemorySource::Session);

        self::assertSame($first->getId(), $second->getId(), 'the objective is one row per session — a write replaces, it does not append');
        self::assertSame('Second aim.', $second->getText());
        self::assertSame(SessionMemorySource::Session, $second->getSource(), 'last writer wins, visibly');
        self::assertSame(2, $second->getRevision());
        self::assertSame(1, $this->countObjectiveRows((int) $task->getId()), 'however many writes, one objective row exists');

        $history = $this->revisionsFor($second);
        self::assertCount(1, $history, 'the superseded text is kept');
        self::assertSame('First aim.', $history[0]->getText());
        self::assertSame(1, $history[0]->getRevision());
        self::assertSame(SessionMemorySource::Operator, $history[0]->getSource(), 'with the writer it belonged to — a steer stays attributable');
    }

    public function testNotesAgeHotToColdOldestFirstWithPinnedSkipped(): void
    {
        $store = $this->store(hot: 2, cold: 3);
        $task = $this->sessionTask();
        $taskId = (int) $task->getId();

        $first = $store->addNote($task, 'note one', SessionMemorySource::Session);
        $store->addNote($task, 'note two', SessionMemorySource::Session);
        $store->addNote($task, 'note three', SessionMemorySource::Session);

        // Born hot, and the overflow demoted the oldest.
        self::assertTrue($first->isCold(), 'the oldest hot note is demoted first');
        self::assertSame(['note two', 'note three'], $this->texts($this->memories->findNotesFor($taskId, SessionMemoryTier::Hot)));
        self::assertSame(['note one'], $this->texts($this->memories->findNotesFor($taskId, SessionMemoryTier::Cold)));

        // A pin survives the next overflow — the operator keeps it hot.
        $second = $this->noteByText($task, 'note two');
        $second->pin();
        $this->em->flush();
        $store->addNote($task, 'note four', SessionMemorySource::Session);

        self::assertTrue($this->noteByText($task, 'note two')->isHot(), 'a pinned note is skipped by ageing');
        self::assertTrue($this->noteByText($task, 'note two')->isPinned());
        self::assertSame(['note two', 'note four'], $this->texts($this->memories->findNotesFor($taskId, SessionMemoryTier::Hot)));
        self::assertSame(['note one', 'note three'], $this->texts($this->memories->findNotesFor($taskId, SessionMemoryTier::Cold)));
    }

    public function testColdOverflowDropsTheOldestColdNoteAndItsHistory(): void
    {
        $store = $this->store(hot: 1, cold: 1);
        $task = $this->sessionTask();

        $store->addNote($task, 'note one', SessionMemorySource::Session);
        $store->addNote($task, 'note two', SessionMemorySource::Session);

        // Give the (about to be dropped) cold note a revision history, the
        // way an operator edit eventually will — the drop must take it too,
        // or the history would be the unbounded store this table must not be.
        $cold = $this->noteByText($task, 'note one');
        $coldId = (int) $cold->getId();
        $this->em->persist(new SessionMemoryRevision($cold, 1, 'note one, as first written', SessionMemorySource::Session));
        $this->em->flush();

        $store->addNote($task, 'note three', SessionMemorySource::Session);

        self::assertSame(0, $this->countRaw('session_memory', $coldId), 'the oldest cold note is dropped on overflow');
        self::assertSame(0, $this->countRaw('session_memory_revision', $coldId, 'memory_id'), 'and its revision history with it');
        self::assertSame(['note three'], $this->texts($this->memories->findNotesFor((int) $task->getId(), SessionMemoryTier::Hot)));
        self::assertSame(['note two'], $this->texts($this->memories->findNotesFor((int) $task->getId(), SessionMemoryTier::Cold)));
    }

    public function testOneWriteIsBoundedAndRefusalsPersistNothing(): void
    {
        $store = $this->store(writeMax: 10);
        $task = $this->sessionTask();

        try {
            $store->addNote($task, str_repeat('x', 11), SessionMemorySource::Session);
            self::fail('an oversized write must be refused');
        } catch (SessionMemoryWriteException $e) {
            self::assertStringContainsString('TASKLOOM_SESSION_WRITE_MAX_CHARS', $e->getMessage(), 'the refusal names the knob that bounded it');
        }

        try {
            $store->setObjective($task, '   ', SessionMemorySource::Operator);
            self::fail('a blank write must be refused');
        } catch (SessionMemoryWriteException $e) {
            self::assertStringContainsString('blank', $e->getMessage());
        }

        self::assertSame([], $this->memories->findNotesFor((int) $task->getId()));
        self::assertNull($this->memories->findObjectiveFor((int) $task->getId()), 'a refused write persists nothing');
    }

    public function testWritesAreRefusedForANonSessionTask(): void
    {
        $store = $this->store();
        $runKind = new Task('Ordinary task', 'Do the thing.', TaskKind::Run, ToolboxMode::Tags, ['x'], TaskAuthor::User);
        $this->em->persist($runKind);
        $this->em->flush();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session memory belongs to a session');

        $store->setObjective($runKind, 'Nope.', SessionMemorySource::Operator);
    }

    public function testTheObjectiveIsNeverAgedOrDropped(): void
    {
        $store = $this->store(hot: 0, cold: 0);
        $task = $this->sessionTask();

        $objective = $store->setObjective($task, 'The aim, whatever happens.', SessionMemorySource::Operator);
        $store->addNote($task, 'a note with nowhere to live', SessionMemorySource::Session);

        // hot 0 + cold 0: the note was demoted and dropped in one pass; the
        // objective is not a note, not in the tier system, not droppable —
        // which is the whole reason it is a separate shape (§3.2).
        self::assertSame([], $this->memories->findNotesFor((int) $task->getId()));
        self::assertSame($objective->getId(), $this->memories->findObjectiveFor((int) $task->getId())?->getId());
        self::assertSame('The aim, whatever happens.', $this->memories->findObjectiveFor((int) $task->getId())?->getText());
    }

    private function store(int $hot = 5, int $cold = 25, int $writeMax = 2000): SessionMemoryStore
    {
        return new SessionMemoryStore($this->em, $this->memories, hotCap: $hot, coldCap: $cold, writeMaxChars: $writeMax);
    }

    private function sessionTask(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function noteByText(Task $task, string $text): SessionMemory
    {
        foreach ($this->memories->findNotesFor((int) $task->getId()) as $note) {
            if ($text === $note->getText()) {
                return $note;
            }
        }

        self::fail(\sprintf('No note reading "%s" exists.', $text));
    }

    private function countObjectiveRows(int $taskId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM session_memory WHERE task_id = :task AND kind = :kind',
            ['task' => $taskId, 'kind' => 'objective'],
        );
    }

    private function countRaw(string $table, int $id, string $column = 'id'): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $table, $column),
            ['id' => $id],
        );
    }

    /** @return list<SessionMemoryRevision> */
    private function revisionsFor(SessionMemory $memory): array
    {
        return $this->em->createQuery('SELECT r FROM App\Entity\SessionMemoryRevision r WHERE r.memory = :memory ORDER BY r.id ASC')
            ->setParameter('memory', $memory)
            ->getResult();
    }

    /**
     * @param list<SessionMemory> $notes
     *
     * @return list<string>
     */
    private function texts(array $notes): array
    {
        return array_map(static fn (SessionMemory $note): string => $note->getText(), $notes);
    }
}
