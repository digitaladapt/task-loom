<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Context\ContextWindow;
use App\Entity\SessionMemorySource;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\SessionMemoryRepository;
use App\Session\SessionMemoryRenderer;
use App\Session\SessionMemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rendered `## Memories` block (docs/design/SESSION_TASKS.md §4.3, build
 * order step 2): the objective first — carrying who set it — then the hot
 * notes, each tagged with its provenance; cold notes are retained but not
 * injected; and the whole block is bounded by its own percentage, dropping
 * the oldest notes whole, never the objective.
 *
 * The renderer is given hand-built ContextWindows (small limits, explicit
 * percentages) so each shape it renders is asserted literally, and the store
 * is the real one against the database.
 */
final class SessionMemoryRendererTest extends KernelTestCase
{
    /**
     * Characters allowed beyond the note budget for the block's fixed
     * skeleton — heading, notes intro, footer, the objective — and one
     * whole-note overshoot. Recalibrated for the declaration-bearing footer
     * (build order step 4).
     */
    private const int FIXED_SKELETON_ALLOWANCE = 520;

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

    public function testTheStoreHoldsNothingRendersNoBlock(): void
    {
        self::assertNull($this->renderer()->render($this->sessionTask()));
    }

    public function testTheObjectiveRendersFirstCarryingWhoSetIt(): void
    {
        $store = $this->store();
        $task = $this->sessionTask();

        $store->setObjective($task, 'Get the importer feature-complete.', SessionMemorySource::Operator);
        $block = $this->renderer()->render($task);

        self::assertNotNull($block);
        self::assertStringContainsString('## Memories', $block);
        self::assertStringContainsString('**Objective** — set by the operator: Get the importer feature-complete.', $block);
        self::assertLessThan(
            strpos($block, 'carried notes') ?: \PHP_INT_MAX,
            strpos($block, '**Objective**') ?: \PHP_INT_MAX,
            'the objective comes first, before the notes',
        );

        // Last writer wins, visibly: the session superseding a steer is seen
        // doing it, not caught doing it (§6.3).
        $store->setObjective($task, 'Ship it, then iterate.', SessionMemorySource::Session);
        $block = $this->renderer()->render($task);

        self::assertNotNull($block);
        self::assertStringContainsString('**Objective** — set by you: Ship it, then iterate.', $block);
        self::assertStringNotContainsString('set by the operator', $block, 'the superseded steer is not what the model is shown');
    }

    public function testNotesRenderTaggedByProvenanceAndColdNotesAreNotInjected(): void
    {
        $store = $this->store(hot: 1, cold: 25);
        $task = $this->sessionTask();

        $store->addNote($task, 'Migration 0007 adds the store.', SessionMemorySource::Session);
        $store->addNote($task, 'Keep PRs small — one concern each.', SessionMemorySource::Operator);
        // hot=1: the first note aged to cold, so only the second is injected.

        $block = $this->renderer()->render($task);

        self::assertNotNull($block);
        self::assertStringContainsString('- [operator] Keep PRs small — one concern each.', $block, 'operator notes are directives');
        self::assertStringNotContainsString('Migration 0007 adds the store.', $block, 'cold notes are retained, not injected');
    }

    public function testThePercentageBackstopDropsTheOldestNotesWholeAndNeverTheObjective(): void
    {
        $store = $this->store();
        $task = $this->sessionTask();
        $store->setObjective($task, 'The aim that must survive.', SessionMemorySource::Operator);

        // Long notes, so dropping one meaningfully shrinks the block — the
        // marker that replaces it costs less than the note it stands for.
        $texts = [];
        for ($i = 1; $i <= 4; ++$i) {
            $texts[$i] = \sprintf('note %d: %s', $i, str_repeat('n', 200));
            $store->addNote($task, $texts[$i], SessionMemorySource::Session);
        }

        $generous = $this->renderer(budgetTokens: 100_000)->render($task);
        self::assertNotNull($generous);
        self::assertStringNotContainsString('omitted', $generous, 'with room to spare, nothing is dropped');
        foreach ($texts as $text) {
            self::assertStringContainsString($text, $generous);
        }

        // A budget 100 characters under the full block: enough that exactly
        // the oldest note must go, not enough that the marker replaces it at
        // equal cost.
        $full = \strlen($generous);
        $tightLimit = (int) ceil(($full - 100) / 0.35) + 1;
        $tight = $this->renderer(budgetTokens: $tightLimit)->render($task);

        self::assertNotNull($tight);
        self::assertStringContainsString('**Objective** — set by the operator: The aim that must survive.', $tight, 'the objective is never dropped');
        self::assertStringContainsString('omitted', $tight, 'the drop is visible, not silent');
        self::assertStringNotContainsString($texts[1], $tight, 'the oldest note is dropped whole, not shown half-written');
        self::assertStringContainsString($texts[4], $tight, 'the newest note is the last thing to go');
        self::assertLessThan($full, \strlen($tight), 'the tight render is smaller than the generous one');
    }

    public function testAnOperatorPinsOverflowStillRendersBounded(): void
    {
        // Pins beyond the hot cap are the operator's explicit choice; the
        // percentage backstop is what still bounds the request (§3.2).
        $store = $this->store(hot: 1, cold: 25);
        $task = $this->sessionTask();

        for ($i = 1; $i <= 3; ++$i) {
            $store->addNote($task, \sprintf('pinned note %d %s', $i, str_repeat('p', 120)), SessionMemorySource::Session);
        }
        foreach ($this->memories->findNotesFor((int) $task->getId()) as $note) {
            $note->pin();
        }
        $this->em->flush();

        self::assertCount(3, $this->memories->findNotesFor((int) $task->getId(), \App\Entity\SessionMemoryTier::Hot), 'pins are skipped by ageing');

        $rendered = $this->renderer(budgetTokens: 700, pct: 10.0)->render($task);
        self::assertNotNull($rendered);
        // The allowance beyond the note budget covers the block's fixed
        // skeleton (heading, notes intro, footer, the objective) plus one
        // whole-note overshoot — the backstop drops whole notes, so the last
        // kept one can cross the line by its own length. The skeleton grew
        // when the footer began naming the declaration tool, which is why
        // the constant is named here rather than tuned silently.
        self::assertLessThanOrEqual((int) floor(700 * 3.5 * 10 / 100) + self::FIXED_SKELETON_ALLOWANCE, \strlen($rendered), 'the backstop keeps even a pins-overflow block near its budget (whole notes, so the last kept note can overshoot by one note)');
    }

    /**
     * The block is a pure function of the stored state, so re-rendering it on
     * **every** request cannot change a byte (§6.3).
     *
     * This is the premise B3 rests on. The block is rebuilt from the store on
     * every request (the §4.1 seam: an operator edit lands on the next
     * request, not at a slice boundary). That is only free of cache
     * consequence because an unchanged store yields an unchanged block —
     * otherwise the seam would silently break the prefix on every turn,
     * whether or not anyone wrote anything. Measured, a *constant* block at
     * this position costs nothing to cache (`KV_CACHE_POSITION_FINDINGS.md`,
     * 93.2% — identical to folding it into the head), so the block is a
     * cache-break exactly when the store changed, and at no other time.
     */
    public function testTheBlockIsPureSoAnUnchangedStoreRerendersItByteIdentically(): void
    {
        $store = $this->store();
        $task = $this->sessionTask();
        $store->setObjective($task, 'Get the importer feature-complete.', SessionMemorySource::Operator);
        $store->addNote($task, 'Migration 0007 adds the store.', SessionMemorySource::Session);
        $store->addNote($task, 'The importer is called from the CLI.', SessionMemorySource::Session);

        $renderer = $this->renderer();

        // Nothing written in between: byte-identical, so a request that
        // changed nothing cannot have broken the prefix.
        $first = $renderer->render($task);
        self::assertNotNull($first);
        self::assertSame($first, $renderer->render($task), 'an unchanged store re-renders the block byte-for-byte');
        self::assertSame($first, $renderer->render($task));

        // A write is the only thing that changes it — and it appends, so what
        // is disturbed is the notes behind it, never the objective before it.
        $store->addNote($task, 'The parser drops comments.', SessionMemorySource::Session);
        $after = $renderer->render($task);
        self::assertNotSame($first, $after);
        self::assertSame(
            \substr($first, 0, \strpos($first, '- [you] Migration 0007')),
            \substr($after, 0, \strpos($after, '- [you] Migration 0007')),
            'a write does not disturb anything before the notes it appends behind',
        );
    }

    private function renderer(int $budgetTokens = 100_000, float $pct = 10.0): SessionMemoryRenderer
    {
        return new SessionMemoryRenderer(
            $this->memories,
            new ContextWindow(contextLimitTokens: $budgetTokens, maxToolOutputPct: 15.0, windowTailExchanges: 10, maxInputArtifactPct: 50.0, maxSessionMemoryPct: $pct),
        );
    }

    private function store(int $hot = 5, int $cold = 25): SessionMemoryStore
    {
        return new SessionMemoryStore($this->em, $this->memories, hotCap: $hot, coldCap: $cold);
    }

    private function sessionTask(): Task
    {
        $task = new Task('Importer session', 'Keep working the importer.', TaskKind::Session, ToolboxMode::Explicit, [], TaskAuthor::User);
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }
}
