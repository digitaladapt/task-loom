<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Entity\Task;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;

/**
 * The task tools exposed over the MCP server role (SPEC §10: the seeded
 * reviewer task's toolbox — task_list, task_get, task_create, task_update).
 *
 * Handlers are plain methods on this service; the server factory
 * registers them via ServerBuilder::withTool() with explicit input
 * schemas. Handlers always return plain arrays — never entities — so the
 * SDK's CallToolResult formatting stays a boring, predictable JSON blob.
 *
 * The write gate is not here. It lives in TaskCrud (SPEC §4.3) — the
 * persistence layer, below any prompt or tool contract.
 */
final class TaskTools
{
    public function __construct(
        private readonly TaskCrud $crud,
    ) {
    }

    /**
     * Create a new task proposal.
     *
     * Writes are gated (SPEC §4.3): the task is persisted disabled and
     * appears in the human approval queue. There is no way to create an
     * enabled task through this tool.
     *
     * @param list<string> $toolbox
     */
    /**
     * @param list<string> $toolbox
     *
     * @return array<string, mixed>
     */
    public function create(
        string $title,
        string $brief,
        TaskKind $kind,
        ToolboxMode $toolboxMode,
        array $toolbox,
        ?string $schedule = null,
    ): array {
        $task = $this->crud->create($title, $brief, $kind, $toolboxMode, $toolbox, $schedule);

        return [
            'id' => $task->getId(),
            'status' => 'draft',
            'note' => 'Persisted disabled — awaiting human approval (SPEC §4.3).',
        ];
    }

    /**
     * Update a task. Enabled tasks are immutable (SPEC §4.4): the update
     * returns a disabled replacement draft; the original keeps running.
     * Draft tasks are edited in place.
     *
     * @param array{title?: string, brief?: string, kind?: TaskKind, toolbox_mode?: ToolboxMode, toolbox?: list<string>, schedule?: ?string} $changes
     */
    /**
     * @param array{title?: string, brief?: string, kind?: TaskKind, toolbox_mode?: ToolboxMode, toolbox?: list<string>, schedule?: ?string} $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $taskId, array $changes): array
    {
        $task = $this->crud->update($taskId, $changes);

        $isReplacement = null !== $task->getReplacementFor();

        return [
            'id' => $task->getId(),
            'status' => $task->isDraft() ? 'draft' : 'replacement_draft',
            'replacement_for' => $task->getReplacementFor()?->getId(),
            'note' => $isReplacement
                ? 'Enabled task is immutable — created a disabled replacement draft; approve it in the UI to swap (SPEC §4.4).'
                : 'Draft updated in place.',
        ];
    }

    /**
     * List tasks, newest first. Read-only.
     *
     * @return list<array<string, mixed>>
     */
    public function list(bool $includeArchived = false): array
    {
        return array_map(
            self::summarize(...),
            $this->crud->list($includeArchived),
        );
    }

    /**
     * Get one task's full record, including its replacement chain.
     */
    /**
     * @return array<string, mixed>
     */
    public function get(int $taskId): array
    {
        $task = $this->crud->get($taskId);

        return $this->present($task);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'brief' => $task->getBrief(),
            'kind' => $task->getKind()->value,
            'toolbox_mode' => $task->getToolboxMode()->value,
            'toolbox' => $task->getToolbox(),
            'schedule' => $task->getSchedule(),
            'enabled' => $task->isEnabled(),
            'archived' => $task->isArchived(),
            'created_by' => $task->getCreatedBy()->value,
            'replacement_for' => $task->getReplacementFor()?->getId(),
            'superseded_by' => $task->getSupersededBy()?->getId(),
            'created_at' => $task->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $task->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'status' => $task->isDraft() ? 'draft' : ($task->isEnabled() ? 'enabled' : 'disabled'),
            'archived' => $task->isArchived(),
        ];
    }
}
