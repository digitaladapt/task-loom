<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\TaskRepository;
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
 * draft; the original is never touched.
 */
final class TaskCrud
{
    public function __construct(
        private readonly TaskRepository $tasks,
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
            $draft = $task->createReplacementDraft(TaskAuthor::Agent);
            $this->applyChanges($draft, $changes);
            $this->tasks->save($draft);

            return $draft;
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
