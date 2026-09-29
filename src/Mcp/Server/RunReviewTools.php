<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Admin\RunDigest;
use App\Entity\Run;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use Mcp\Exception\ToolCallException;

/**
 * The reviewer's toolbox (SPEC §10): run_review and run_read_log.
 *
 * Both are read-only, deterministic, and budget-bounded. Nothing here runs
 * an LLM: the digest aggregates what the ledger already recorded, and the
 * judgement about what to change is the caller's — whether that caller is a
 * seeded reviewer task, an external agent, or a human at a terminal.
 *
 * SPEC §10 names the reviewer's toolbox as task_list, task_get,
 * run_read_log, task_create, task_update. run_review is the addition: the
 * five of those verbs say nothing about what a run DID, and reconstructing
 * "did this task call the same tool four times with the same arguments" by
 * paging raw logs burns a reviewer's entire context budget on arithmetic.
 * It is the same distinction as the scheduler's narration: aggregate
 * server-side, let the model interpret.
 *
 * Neither tool writes. Proposing a change means calling task_update, which
 * is gated to a disabled draft by TaskCrud wherever it is called from
 * (SPEC §4.3) — the human gate is unchanged by anything in this file.
 *
 * Argument errors are translated to the SDK's ToolCallException: it is the
 * one exception the SDK renders verbatim as an isError result, so a caller
 * that asked for an unknown bucket or a nonsensical budget gets the
 * correction rather than a bare "internal error" (the same reasoning as
 * TaskTools' step-format handling).
 */
final class RunReviewTools
{
    public function __construct(
        private readonly RunDigest $digest,
        private readonly TaskRepository $tasks,
        private readonly RunRepository $runs,
    ) {
    }

    /**
     * Digest a task's most recent settled run (or a specific one).
     *
     * @return array<string, mixed>
     */
    public function review(
        int $taskId,
        ?int $runId = null,
        int $budgetChars = RunDigest::DEFAULT_REVIEW_BUDGET,
        ?int $history = null,
    ): array {
        $task = $this->tasks->find($taskId);
        if (null === $task) {
            throw new ToolCallException(\sprintf('No task with id %d.', $taskId));
        }

        $run = null;
        if (null !== $runId) {
            $run = $this->runs->find($runId);
            if (null === $run) {
                throw new ToolCallException(\sprintf('No run with id %d.', $runId));
            }

            // A run id from another task is a caller mistake worth naming:
            // silently digesting an unrelated run would produce a confident,
            // wrong review.
            if ($run->getTask()->getId() !== $task->getId()) {
                throw new ToolCallException(\sprintf('Run %d belongs to task %s, not task %d.', $runId, (string) $run->getTask()->getId(), $taskId));
            }
        }

        try {
            return $this->digest->review($task, $run, $budgetChars, $history);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Read a run's ledger entries, by kind, within a character budget.
     *
     * @param list<string> $include
     *
     * @return array<string, mixed>
     */
    public function readLog(
        int $runId,
        array $include = [],
        int $budgetChars = 8000,
        int $entryChars = 4000,
    ): array {
        $run = $this->runs->find($runId);
        if (!$run instanceof Run) {
            throw new ToolCallException(\sprintf('No run with id %d.', $runId));
        }

        try {
            return $this->digest->readLog($run, $include, $budgetChars, $entryChars);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
