<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;

/**
 * The gated task CRUD persistence layer (SPEC §4.3).
 *
 * Every write performed through the task MCP tools routes through this
 * service, and every one of those writes persists with enabled = false.
 * That is not configurable. There is no flag, no prompt instruction, and
 * no argument that can change it — the gate lives here, in the
 * persistence layer, below any caller.
 *
 * This service also implements the SPEC §4.4 replacement semantics for
 * updates: an update to an enabled task creates a disabled replacement
 * draft; the original is never touched. The draft carries the task's
 * step graph too (SPEC §13: steps are task content) — the depends_on
 * edges are remapped onto the cloned step rows.
 */
final class TaskCrud
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly StepRepository $steps,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Create a task. Agent-authored tasks are always persisted disabled —
     * the enabled flag does not exist as an argument.
     *
     * @param list<string> $toolbox
     */
    public function create(
        string $title,
        string $brief,
        TaskKind $kind,
        ToolboxMode $toolboxMode,
        array $toolbox,
        ?string $schedule,
    ): Task {
        $task = new Task($title, $brief, $kind, $toolboxMode, $toolbox, TaskAuthor::Agent);
        $task->setSchedule($schedule);

        $this->tasks->save($task);

        return $task;
    }

    /**
     * Update a task. Enabled tasks are immutable (SPEC §4.4): the update
     * returns a disabled replacement draft carrying the edits; the
     * original keeps running untouched. Draft tasks are edited in place.
     *
     * @param array{title?: string, brief?: string, kind?: TaskKind, toolbox_mode?: ToolboxMode, toolbox?: list<string>, schedule?: ?string} $changes
     */
    public function update(int $taskId, array $changes): Task
    {
        $task = $this->findOrThrow($taskId);

        if ($task->isEnabled()) {
            return $this->createReplacementDraft($task, $changes);
        }

        if ($task->isArchived()) {
            throw new EntityNotFoundException("Task {$taskId} is archived and cannot be updated.");
        }

        $this->applyChanges($task, $changes);
        $this->tasks->save($task);

        return $task;
    }

    /**
     * List tasks, newest first. Read-only — no gate concerns.
     *
     * @return list<Task>
     */
    public function list(bool $includeArchived = false): array
    {
        $criteria = $includeArchived ? [] : ['archivedAt' => null];

        return $this->tasks->findBy($criteria, ['id' => 'DESC']);
    }

    public function get(int $taskId): Task
    {
        return $this->findOrThrow($taskId);
    }

    /**
     * The SPEC §4.4 replacement path: draft creation, the edits, and the
     * step-graph copy land in ONE transaction (the engine's commitTurn
     * pattern). A replacement that fails mid-way — half-copied steps, a
     * draft without its graph — must never persist; either the whole
     * replacement exists or nothing does.
     *
     * @param array{title?: string, brief?: string, kind?: TaskKind, toolbox_mode?: ToolboxMode, toolbox?: list<string>, schedule?: ?string} $changes
     */
    private function createReplacementDraft(Task $original, array $changes): Task
    {
        $draft = $original->createReplacementDraft(TaskAuthor::Agent);
        $this->applyChanges($draft, $changes);

        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $this->em->persist($draft);
            $this->em->flush();
            $this->copySteps($original, $draft);

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->em->clear(); // torn entities must not leak into the next call
            }

            throw $e;
        }

        return $draft;
    }

    /**
     * Copy a task's step graph onto its replacement draft, remapping the
     * depends_on edges onto the cloned rows (SPEC §13.1: a replacement
     * draft carries the task's content). Two phases: clone with no edges
     * (ids are assigned by the flush), then translate the edges and flush
     * again. The source graph was validated when its task was enabled
     * (SPEC §13.2), so every edge resolves; a defensive skip keeps this
     * total if it ever did not.
     */
    private function copySteps(Task $from, Task $to): void
    {
        $steps = $this->steps->findForTask($from);
        if ([] === $steps) {
            return;
        }

        /** @var array<int, Step> $clones old step id → clone */
        $clones = [];
        /** @var array<int, list<int>> $edges old step id → original depends_on */
        $edges = [];

        foreach ($steps as $step) {
            $oldId = $step->getId();
            if (null === $oldId) {
                continue; // defensive: every step read from the DB has an id
            }

            $clone = new Step(
                $to,
                $step->getPosition(),
                $step->getTitle(),
                $step->getBrief(),
                $step->getToolboxMode(),
                $step->getToolbox(),
            );
            $this->em->persist($clone);

            $clones[$oldId] = $clone;
            $edges[$oldId] = $step->getDependsOn();
        }

        if ([] === $clones) {
            return;
        }
        $this->em->flush();

        /** @var array<int, int> $newIds old step id → new step id */
        $newIds = [];
        foreach ($clones as $oldId => $clone) {
            $newId = $clone->getId();
            if (null !== $newId) {
                $newIds[$oldId] = $newId;
            }
        }

        foreach ($clones as $oldId => $clone) {
            $translated = [];
            foreach ($edges[$oldId] as $dep) {
                if (isset($newIds[$dep])) {
                    $translated[] = $newIds[$dep];
                }
            }
            $clone->setDependsOn($translated);
        }
        $this->em->flush();
    }

    /**
     * @param array{title?: string, brief?: string, kind?: TaskKind, toolbox_mode?: ToolboxMode, toolbox?: list<string>, schedule?: ?string} $changes
     */
    private function applyChanges(Task $task, array $changes): void
    {
        if (\array_key_exists('title', $changes)) {
            $task->setTitle($changes['title']);
        }
        if (\array_key_exists('brief', $changes)) {
            $task->setBrief($changes['brief']);
        }
        if (\array_key_exists('kind', $changes)) {
            $task->setKind($changes['kind']);
        }
        if (\array_key_exists('toolbox_mode', $changes)) {
            $task->setToolboxMode($changes['toolbox_mode']);
        }
        if (\array_key_exists('toolbox', $changes)) {
            $task->setToolbox($changes['toolbox']);
        }
        if (\array_key_exists('schedule', $changes)) {
            $task->setSchedule($changes['schedule']);
        }
    }

    private function findOrThrow(int $taskId): Task
    {
        $task = $this->tasks->find($taskId);
        if (null === $task) {
            throw EntityNotFoundException::fromClassNameAndIdentifier(Task::class, ['id' => (string) $taskId]);
        }

        // The MCP serve process is long-lived; entities cached in its
        // identity map go stale when tasks are enabled or archived out of
        // band (admin UI, DB, run lifecycle). The write gate's decision must
        // be based on the persisted row, not a cached snapshot — otherwise an
        // enabled task looks like an editable draft and the gate is bypassed.
        $this->tasks->refresh($task);

        return $task;
    }
}
