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
use App\Scheduler\ScheduleExpression;
use App\StepModel\StepGraphCodec;
use App\StepModel\StepGraphValidator;
use App\StepModel\StepSpec;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;

/**
 * The gated task CRUD persistence layer (SPEC §4.3): the single write path
 * for task content, shared by both authoring entry points — the task MCP
 * tools (author = agent) and the admin editor (author = user).
 *
 * Every write through this service persists with enabled = false. That is
 * not configurable. There is no flag, no prompt instruction, and no
 * argument that can change it — the gate lives here, in the persistence
 * layer, below any caller. Enabling is a human lifecycle action
 * (App\Admin\TaskAdminService), never a write.
 *
 * Sharing one path is what keeps the two entry points from drifting: the
 * gate, the replacement semantics, and the step-graph transaction are
 * identical whether an agent or the human's browser authored the change.
 * The author is recorded on created_by so the approval queue can say who
 * proposed what.
 *
 * This service also implements the SPEC §4.4 replacement semantics for
 * updates: an update to a *record* — a task that is enabled, or that has
 * ever run — creates a disabled replacement draft; the original is never
 * touched. The draft carries the task's step graph too (SPEC §13: steps are
 * task content) — replaced when the update supplies a new graph, cloned with
 * remapped edges when it does not. Only a never-enabled, never-run draft is
 * edited in place.
 *
 * "Has run" is the sharper half of that gate, and it is read here, from the
 * runs table, at the moment of the write. Keying only on `enabled` was a live
 * incident: a disabled task that had run fell back to the in-place branch,
 * replaceSteps() deleted step rows that run.step_id still pointed at, and the
 * task's run surface stopped loading entirely. The enabled flag is not the
 * question; whether the version is a record is.
 *
 * Step graphs arrive in the authoring/wire format (nested arrays, SPEC
 * §13.2) and are translated to depends_on edges here, in the same
 * transaction as the task write: a half-written graph never persists.
 * Graph shape problems (StepFormatException) are raised before any
 * database work.
 */
final class TaskCrud
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly StepRepository $steps,
        private readonly StepGraphCodec $codec,
        private readonly StepGraphValidator $graphValidator,
        private readonly ScheduleExpression $scheduleExpression,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Create a task. Every task is persisted disabled — the enabled flag
     * does not exist as an argument, for either author.
     *
     * @param list<string> $toolbox
     * @param mixed        $steps   wire-format step graph (nested arrays, SPEC §13.2); null = no steps
     * @param TaskAuthor   $author  who is proposing this task: agent (MCP tools), user (admin editor)
     *
     * @throws \App\StepModel\StepFormatException     when the steps input is malformed
     * @throws \App\Scheduler\ScheduleFormatException when the schedule is not a valid cron expression
     */
    public function create(
        string $title,
        string $brief,
        TaskKind $kind,
        ToolboxMode $toolboxMode,
        array $toolbox,
        ?string $schedule,
        mixed $steps = null,
        TaskAuthor $author = TaskAuthor::Agent,
    ): Task {
        $specs = $this->codec->parse($steps);

        // SPEC §14: refuse an invalid schedule at authoring time — the same
        // discipline as the step format (§13.2). Enable/approve validates
        // again, where it is the enforcement gate.
        $this->scheduleExpression->assertValid($schedule);

        $task = new Task($title, $brief, $kind, $toolboxMode, $toolbox, $author);
        $task->setSchedule(ScheduleExpression::normalize($schedule));

        return $this->inTransaction(function () use ($task, $specs): Task {
            $this->em->persist($task);
            $this->em->flush();

            if ([] !== $specs) {
                $this->replaceSteps($task, $specs);
            }

            return $task;
        });
    }

    /**
     * Update a task. A task that is enabled, or that has ever run, is an
     * immutable record (SPEC §4.4): the update returns a disabled replacement
     * draft carrying the edits; the original is untouched. A never-enabled,
     * never-run draft is edited in place.
     *
     * The `steps` change key (SPEC §13.2) replaces the task's entire step
     * graph with the supplied wire-format graph; an empty array clears
     * it. When the key is absent, the graph is left untouched — and for a
     * replacement draft, the original's graph is cloned onto it.
     *
     * @param array{title?: string, brief?: string, kind?: TaskKind|string, toolbox_mode?: ToolboxMode|string, toolbox?: list<string>, schedule?: ?string, steps?: mixed} $changes
     * @param TaskAuthor                                                                                                                                                  $author  recorded on a generated replacement draft
     *
     * @throws \App\StepModel\StepFormatException     when the steps input is malformed
     * @throws \App\Scheduler\ScheduleFormatException when the schedule is not a valid cron expression
     */
    public function update(int $taskId, array $changes, TaskAuthor $author = TaskAuthor::Agent): Task
    {
        $task = $this->findOrThrow($taskId);

        if ($task->isArchived()) {
            throw new EntityNotFoundException("Task {$taskId} is archived and cannot be updated.");
        }

        $hasSteps = \array_key_exists('steps', $changes);
        $specs = $hasSteps ? $this->codec->parse($changes['steps']) : null;

        // SPEC §4.4: the gate is "is this version a record?", and a run makes
        // it one. The runs table is the authority, so read it here — the
        // identity map's word for it is from whenever the row was loaded, and
        // a task that ran since is exactly the case this has to catch.
        if ($this->tasks->hasRuns($task)) {
            $task->markHasRuns();
        }

        if ($task->isContentLocked()) {
            return $this->createReplacementDraft($task, $changes, $specs, $author);
        }

        return $this->inTransaction(function () use ($task, $changes, $hasSteps, $specs): Task {
            $this->applyChanges($task, $changes);
            $this->em->flush();

            if ($hasSteps) {
                $this->replaceSteps($task, $specs ?? []);
            }

            return $task;
        });
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
     * A task's step graph in display order (SPEC §13) — read-only. The MCP
     * tools render it back out in the authoring/wire format via
     * StepGraphCodec::render().
     *
     * @return list<Step>
     */
    public function stepsFor(Task $task): array
    {
        return $this->steps->findForTask($task);
    }

    /**
     * The SPEC §4.4 replacement path: draft creation, the edits, and the
     * step graph (replaced from the wire format, or cloned from the
     * original) land in ONE transaction (the engine's commitTurn
     * pattern). A replacement that fails mid-way — half-written steps, a
     * draft without its graph — must never persist; either the whole
     * replacement exists or nothing does.
     *
     * @param array{title?: string, brief?: string, kind?: TaskKind|string, toolbox_mode?: ToolboxMode|string, toolbox?: list<string>, schedule?: ?string, steps?: mixed} $changes
     * @param list<StepSpec>|null                                                                                                                                         $specs   null = clone the original's graph
     */
    private function createReplacementDraft(Task $original, array $changes, ?array $specs, TaskAuthor $author): Task
    {
        $draft = $original->createReplacementDraft($author);
        $this->applyChanges($draft, $changes);

        // The draft deliberately does NOT inherit the original's hasRuns.
        // Record-ness is per version, and the draft is a version that has not
        // run: its own step rows are deletable precisely because nothing
        // points at them yet. Inheriting it would make the draft unbuildable
        // — replaceSteps() constructs its steps, and the content guard would
        // refuse its own graph — while protecting nothing: every run in the
        // store points at the *original's* steps, and the replacement path
        // never touches those (it clones them onto new rows).

        return $this->inTransaction(function () use ($original, $draft, $specs): Task {
            $this->em->persist($draft);
            $this->em->flush();

            if (null !== $specs) {
                $this->replaceSteps($draft, $specs);
            } else {
                $this->copySteps($original, $draft);
            }

            return $draft;
        });
    }

    /**
     * Replace a task's entire step graph with the parsed specs (SPEC
     * §13.2): existing rows go, new rows arrive — positions assigned in
     * wire order, depends_on edges translated from the level structure
     * (each step depends on every step of the previous level).
     *
     * Two phases: persist all rows first (the flush assigns ids), then
     * write edges, then flush. Runs inside the caller's transaction — a
     * failure rolls the whole task write back.
     *
     * @param list<StepSpec> $specs
     */
    private function replaceSteps(Task $task, array $specs): void
    {
        $this->sweepRefsTo($task);

        foreach ($this->steps->findForTask($task) as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();

        /** @var list<array{0: Step, 1: StepSpec}> $created */
        $created = [];
        $position = 0;

        foreach ($specs as $spec) {
            $step = new Step(
                $task,
                ++$position,
                $spec->title,
                $spec->brief,
                $spec->toolboxMode,
                $spec->toolbox,
            );
            $this->em->persist($step);
            $created[] = [$step, $spec];
        }
        $this->em->flush();

        /** @var array<int, list<int>> $idsByLevel */
        $idsByLevel = [];
        foreach ($created as [$step, $spec]) {
            $id = $step->getId();
            if (null !== $id) {
                $idsByLevel[$spec->level][] = $id;
            }
        }

        foreach ($created as [$step, $spec]) {
            $step->setDependsOn($spec->level > 0 ? ($idsByLevel[$spec->level - 1] ?? []) : []);
        }
        $this->em->flush();

        // SPEC §13.2: validation runs at create/update too. The wire
        // format cannot express an invalid graph (edges point strictly
        // backward by level), so a failure here is a translation bug, not
        // authoring input — fail loudly, and let the transaction roll the
        // write back.
        $problems = $this->graphValidator->problems($this->steps->findForTask($task));
        if ([] !== $problems) {
            throw new \LogicException('Step graph translation produced an invalid graph: '.implode(' ', $problems));
        }
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
     * The engine's commitTurn transaction pattern: own the transaction
     * when nobody above us does; roll back (and detach torn entities) on
     * any failure so nothing half-written leaks into the next call.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $result = $work();

            if ($ownsTransaction) {
                $connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->em->clear(); // torn entities must not leak into the next call
            }

            throw $e;
        }
    }

    /**
     * @param array{title?: string, brief?: string, kind?: TaskKind|string, toolbox_mode?: ToolboxMode|string, toolbox?: list<string>, schedule?: ?string, steps?: mixed} $changes
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
            $task->setKind($this->coerceKind($changes['kind']));
        }
        if (\array_key_exists('toolbox_mode', $changes)) {
            $task->setToolboxMode($this->coerceToolboxMode($changes['toolbox_mode']));
        }
        if (\array_key_exists('toolbox', $changes)) {
            $task->setToolbox($changes['toolbox']);
        }
        if (\array_key_exists('schedule', $changes)) {
            // SPEC §14: an invalid schedule is refused at authoring time
            // (the enable/approve gate validates again).
            $this->scheduleExpression->assertValid($changes['schedule']);
            $task->setSchedule(ScheduleExpression::normalize($changes['schedule']));
        }
        // 'steps' is not applied here: it is not a field but a graph
        // replacement, handled by replaceSteps() in the same transaction.
    }

    /**
     * The MCP wire format carries enums as strings (the tool schemas
     * declare string enums, and the SDK passes nested values through
     * untyped). Coerce here, at the persistence boundary, so every caller
     * — the MCP tools today, anything later — is insulated from the wire
     * representation.
     */
    private function coerceKind(TaskKind|string $kind): TaskKind
    {
        if ($kind instanceof TaskKind) {
            return $kind;
        }

        return TaskKind::tryFrom($kind) ?? throw new \InvalidArgumentException(\sprintf('Invalid kind "%s" — expected "run" or "session".', $kind));
    }

    private function coerceToolboxMode(ToolboxMode|string $mode): ToolboxMode
    {
        if ($mode instanceof ToolboxMode) {
            return $mode;
        }

        return ToolboxMode::tryFrom($mode) ?? throw new \InvalidArgumentException(\sprintf('Invalid toolbox_mode "%s" — expected "tags" or "explicit".', $mode));
    }

    /**
     * Find a task, or fail naming it.
     */
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

    /**
     * Drop a task's runs' references to step rows that no longer exist. Every
     * step deletion in the codebase runs through here, which is what makes it
     * a trustworthy backstop, and a cheap one: one indexed query, and normally
     * nothing to repair.
     *
     * It exists because the database will not do it for us. run.step_id is
     * declared `ON DELETE SET NULL`, which is the right behavior — but SQLite
     * does not enforce foreign keys unless `PRAGMA foreign_keys` is on, and
     * nothing in this deployment turns it on (the connection middleware sets
     * journal_mode only). So the ON DELETE action is decorative, and a
     * deleted step leaves run.step_id pointing at a row that is gone: the
     * run's step relation becomes an unresolvable Doctrine proxy, and loading
     * the run — or the task page that lists it — throws instead of rendering.
     *
     * Doing it by hand keeps the ledger loadable without depending on a
     * connection pragma. Turning the pragma on is still worth doing, and
     * belongs with the other connection-level SQLite settings; when it lands,
     * this sweep becomes a no-op that costs one query.
     */
    private function sweepRefsTo(Task $task): void
    {
        // Scoped by run.task_id as well as by the step set, and that is not
        // belt-and-braces: `step_id NOT IN (SELECT ...)` is TRUE for every row
        // when the set is empty, so an unscoped form would clear references
        // belonging to *other* tasks — which is exactly what it did the first
        // time, on the path where the task being written is itself empty (a
        // freshly created replacement draft, before its graph is built).
        $this->em->getConnection()->executeStatement(
            'UPDATE run SET step_id = NULL'
            .' WHERE task_id = :task AND step_id IS NOT NULL'
            .' AND step_id NOT IN (SELECT id FROM step WHERE task_id = :task)',
            ['task' => $task->getId()],
        );
    }
}
