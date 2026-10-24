<?php

declare(strict_types=1);

namespace App\Tests\Unit\RunEngine;

use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\RunEngine\BeingStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The frozen head is keyed, and the key decides whether freezing is a cache or a
 * bug.
 *
 * A head embeds `## Grounding` — the date the model is told it is. For a session
 * that instant is *anchored* rather than live (KV_CACHE.md §6.5): it is stored on
 * the task and normally moves only when the deployment day turns. Freezing a
 * slice's head therefore has to key on the anchor, or the very first slice ever
 * compiled is replayed to every later slice and the session is told the same day
 * forever.
 *
 * That failure is invisible in the ordinary test suite: within one day the anchor
 * does not move, so a key that ignores it looks correct. It only shows up the day
 * after, in a session that has been resumed — which is precisely the case a test
 * has to construct deliberately.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class BeingStoreHeadKeyTest extends TestCase
{
    public function testTwoGroundingInstantsProduceTwoKeys(): void
    {
        $store = $this->store();
        $task = $this->task(42);

        $monday = $store->headKey($task, 'slice', new \DateTimeImmutable('2026-10-19 09:00:00'));
        $tuesday = $store->headKey($task, 'slice', new \DateTimeImmutable('2026-10-20 09:00:00'));

        self::assertNotSame(
            $monday,
            $tuesday,
            'the date is part of the head, so it must be part of the key; otherwise a resumed session keeps yesterday',
        );
    }

    /**
     * The other half: a key that changed on every call would be no cache at all.
     *
     * Note what is *not* asserted here — that two different instants on the same
     * day share a key. They must not. The head renders the time too
     * (`Current time: H:i`, {@see \App\Context\Grounding}), so two anchors are two
     * different heads, and a key pretending otherwise would serve one for the
     * other. What matters is that the anchor is stable: the same anchor renders
     * the same head, so repeated calls must agree.
     */
    public function testTheSameAnchorIsTheSameKey(): void
    {
        $store = $this->store();
        $task = $this->task(42);
        $anchor = new \DateTimeImmutable('2026-10-19 09:00:00');

        self::assertSame(
            $store->headKey($task, 'slice', $anchor),
            $store->headKey($task, 'slice', $anchor),
            'a byte-identical head must land in one slot, or nothing is ever reused',
        );
    }

    /**
     * A head carries the task's title, brief, toolbox and completion text, so one
     * task must never be served another's. SPEC §4.1 freezes a run's toolbox at
     * creation; a shared head would hand a run tools it was never granted.
     */
    public function testDifferentTasksNeverShareAHead(): void
    {
        $store = $this->store();
        $at = new \DateTimeImmutable('2026-10-19 09:00:00');

        self::assertNotSame(
            $store->headKey($this->task(1), 'slice', $at),
            $store->headKey($this->task(2), 'slice', $at),
        );
    }

    /**
     * A run's head and a slice's head are compiled by different code paths and
     * have different shapes, so they must not share a slot even for one task.
     */
    public function testRunAndSliceHeadsAreDistinct(): void
    {
        $store = $this->store();
        $task = $this->task(7);

        self::assertNotSame($store->headKey($task, 'run'), $store->headKey($task, 'slice'));
    }

    /**
     * An ordinary run's grounding is the live clock and its head is compiled once
     * per task, so its key stays stable across time — that stability is the point
     * of freezing it.
     */
    public function testAnOrdinaryRunKeyDoesNotDependOnTime(): void
    {
        $store = $this->store();

        self::assertSame($store->headKey($this->task(9), 'run'), $store->headKey($this->task(9), 'run'));
    }

    private function store(): BeingStore
    {
        return new BeingStore($this->createMock(EntityManagerInterface::class));
    }

    private function task(int $id): Task
    {
        $task = new Task('t', 'b', TaskKind::Run, ToolboxMode::Explicit, [], TaskAuthor::User);
        (new \ReflectionProperty(Task::class, 'id'))->setValue($task, $id);

        return $task;
    }
}
