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
        self::assertLessThanOrEqual((int) floor(700 * 3.5 * 10 / 100) + 400, \strlen($rendered), 'the backstop keeps even a pins-overflow block near its budget (whole notes, so the last kept note can overshoot by one note)');
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
