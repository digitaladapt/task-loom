<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Entity\Task;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\StepModel\StepFormatException;
use App\StepModel\StepGraphCodec;
use Mcp\Exception\ToolCallException;

/**
 * The task tools exposed over the MCP server role (SPEC §10: the seeded
 * reviewer task's toolbox — task_list, task_get, task_create, task_update).
 *
 * Handlers are plain methods on this service; the server factory
 * registers them via Builder::addTool() with explicit input
 * schemas. Handlers always return plain arrays — never entities — so the
 * SDK's CallToolResult formatting stays a boring, predictable JSON blob.
 *
 * Steps travel in the authoring/wire format (SPEC §13.2): nested arrays
 * in (create/update), nested arrays rendered back out (get). The
 * depends_on edges are the storage form — this layer never shows them.
 *
 * Step-format problems are translated to the SDK's ToolCallException: it
 * is the one exception the SDK renders verbatim to the caller (as an
 * isError result), which is what makes the codec's indexed diagnosis
 * useful to an authoring agent — a generic throwable would degrade to a
 * bare "internal error" with the detail only in the log.
 *
 * The write gate is not here. It lives in TaskCrud (SPEC §4.3) — the
 * persistence layer, below any prompt or tool contract.
 */
final class TaskTools
{
    public function __construct(
        private readonly TaskCrud $crud,
        private readonly StepGraphCodec $codec,
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
     * @param mixed        $steps   wire-format step graph (SPEC §13.2), null = no steps
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
        mixed $steps = null,
    ): array {
        try {
            $task = $this->crud->create($title, $brief, $kind, $toolboxMode, $toolbox, $schedule, $steps);
        } catch (StepFormatException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        return [
            'id' => $task->getId(),
            'status' => 'draft',
            'steps' => \count($this->crud->stepsFor($task)),
            'note' => 'Persisted disabled — awaiting human approval (SPEC §4.3).',
        ];
    }

    /**
     * Update a task. Enabled tasks are immutable (SPEC §4.4): the update
     * returns a disabled replacement draft; the original keeps running.
     * Draft tasks are edited in place.
     *
     * The `steps` change (SPEC §13.2) replaces the task's entire step
     * graph with the supplied wire-format graph ([] clears it); omitting
     * the key leaves the graph untouched — a replacement draft clones the
     * original's graph in that case.
     *
     * @param array{title?: string, brief?: string, kind?: TaskKind|string, toolbox_mode?: ToolboxMode|string, toolbox?: list<string>, schedule?: ?string, steps?: mixed} $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $taskId, array $changes): array
    {
        try {
            $task = $this->crud->update($taskId, $changes);
        } catch (StepFormatException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        $isReplacement = null !== $task->getReplacementFor();

        return [
            'id' => $task->getId(),
            // isDraft() is true for replacement drafts too (not enabled,
            // not archived) — the chain is what distinguishes them.
            'status' => $isReplacement ? 'replacement_draft' : 'draft',
            'replacement_for' => $task->getReplacementFor()?->getId(),
            'steps' => \count($this->crud->stepsFor($task)),
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
     * Get one task's full record, including its replacement chain. The
     * step graph comes back in the authoring/wire format (SPEC §13.2).
     *
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
            'steps' => $this->codec->render($this->crud->stepsFor($task)),
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
