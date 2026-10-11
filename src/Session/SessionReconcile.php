<?php

declare(strict_types=1);

namespace App\Session;

/**
 * One enabled session's slice reconciliation (docs/design/SESSION_TASKS.md
 * §8): the session half of the requeue sweep's report.
 *
 * `applied` false is a dry run — a slice is owed but nothing was created.
 * `sliceRunId` is the launched slice when one was; a dry run leaves it
 * null. (The launched slice carries its own fate: a constitution that
 * cannot resolve is recorded as a failed slice row, visible in the ledger —
 * never thrown out of a settlement transaction.)
 */
final readonly class SessionReconcile
{
    public function __construct(
        public int $taskId,
        public bool $applied,
        public ?int $sliceRunId,
    ) {
    }
}
