<?php

declare(strict_types=1);

namespace App\Tests\Functional\RunEngine;

use App\Context\ContextWindow;
use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunEventType;
use App\Entity\RunStatus;
use App\Entity\ServerProtocol;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponse;
use App\Repository\RunRepository;
use App\RunEngine\LoopState;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\RunGraph;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Batched shedding end to end (docs/design/KV_CACHE.md §6.2, §7, §9).
 *
 * These are the smoke tests and the benchmark the design note asks for. They
 * drive the REAL engine against the real ledger, with the LLM and tool
 * dispatch stubbed at their interfaces, and read the actual message arrays the
 * engine would put on the wire. The quantity measured is the one §7 names: how
 * much of each request is a reuse of the previous one — here computed
 * deterministically as the longest common message prefix, so it needs no model
 * (llama.cpp is not available in this rig; §9's token-level numbers were taken
 * live and are not re-derived here).
 */
#[AllowMockObjectsWithoutExpectations]
final class RunEngineSheddingSmokeTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private LlmClientInterface&MockObject $llm; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private ToolExecutorInterface&MockObject $executor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private int $chatCount = 0;

    /** @var list<list<array<string, mixed>>> every request the engine sent, in order */
    private array $requests = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StreamEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->executor = $this->createMock(ToolExecutorInterface::class);
        $this->chatCount = 0;
        $this->requests = [];
    }

    /**
     * The smoke test and the benchmark, end to end.
     *
     * The same run — many tool rounds over a budget that forces shedding — is
     * played twice: once with the headroom target at 50% (batched, what we
     * ship) and once at 100% (the target is the limit, so the batch can shed
     * nothing extra, which is exactly the old greedy "stop at the newest that
     * fits" behavior). The observable quantity is documented as §7's: how
     * often the retained front moves, which is how often a prefix-caching
     * server must re-prefill, and the share of each request that is a reuse of
     * the one before it.
     *
     * No live model is needed, so this runs in CI: reuse is the longest common
     * message prefix between consecutive requests, which is precisely the
     * prefix llama.cpp would hit. §9's token-level numbers were taken live
     * against llama.cpp and are not re-derived here.
     */
    public function testBatchedSheddingMovesTheFrontLessOftenThanGreedy(): void
    {
        $batched = $this->runScenario(contextTrimTargetPct: 50.0);
        $greedy = $this->runScenario(contextTrimTargetPct: 100.0);

        // Both runs complete and both shed: the difference is how often.
        self::assertGreaterThanOrEqual(1, $batched['trims']);
        self::assertGreaterThanOrEqual(1, $greedy['trims']);

        // The front moves only at a shed — never on a plain turn (B2 fixed).
        self::assertLessThanOrEqual(
            $batched['trims'],
            $batched['frontMoves'],
            \sprintf('the front moved %d times for %d trims — a sticky front moves only at a shed', $batched['frontMoves'], $batched['trims']),
        );

        // And batching re-prefills strictly less often than the greedy path.
        self::assertLessThan(
            $greedy['frontMoves'],
            $batched['frontMoves'],
            'the headroom target sheds a whole batch, so the front moves fewer times than the greedy fallback',
        );

        // The trim row records where the front landed, so the shed is visible
        // and the next turn's anchor is auditable.
        self::assertArrayHasKey('contextBase', $batched['trimPayload']);
        self::assertIsInt($batched['trimPayload']['contextBase']);

        fwrite(\STDERR, \sprintf(
            "\n[shedding benchmark] turns=%d\n  batched(50%%): trims=%d front-moves=%d reuse(median)=%.1f%% reuse(min)=%.1f%%\n  greedy(100%%): trims=%d front-moves=%d reuse(median)=%.1f%% reuse(min)=%.1f%%\n",
            $batched['turns'],
            $batched['trims'], $batched['frontMoves'], $batched['medianReuse'], $batched['minReuse'],
            $greedy['trims'], $greedy['frontMoves'], $greedy['medianReuse'], $greedy['minReuse'],
        ));
    }

    /**
     * Play the scenario once and measure it. A run of many tool rounds against
     * a budget the head fits but the tail overflows, with the real engine and
     * the real ledger; the LLM and tool dispatch are stubbed.
     *
     * @return array{turns: int, trims: int, frontMoves: int, medianReuse: float, minReuse: float, trimPayload: array<string, mixed>}
     */
    private function runScenario(float $contextTrimTargetPct): array
    {
        $this->resetWorld();

        $rounds = 14;
        $this->catalogTool('get_weather');
        $task = $this->enabledTask(toolbox: ['get_weather'], mode: ToolboxMode::Explicit);

        $this->scriptToolRounds($rounds);
        $this->executor->method('validate')->willReturn([]);
        $this->executor->method('execute')->willReturnCallback(
            fn (): array => ['tool' => 'get_weather', 'content' => str_repeat('w', 600), 'isError' => false, 'durationMs' => 1],
        );

        $container = static::getContainer();
        $compiler = $container->get(PromptCompiler::class);
        $tools = $container->get(ToolboxResolver::class)->resolve($task);
        $head = $compiler->compile($task, $tools);

        // A budget with a small head and real tail room, so a 50% target
        // leaves something to shed *to* — otherwise batching has nothing to
        // buy and the two policies coincide.
        $fixedChars = \strlen($head['system']) + \strlen($head['user'])
            + \strlen((string) json_encode($compiler->toolsToOpenAi($tools)));
        $window = new ContextWindow(
            contextLimitTokens: (int) ceil($fixedChars / 3.5) + 1500,
            maxToolOutputPct: 15.0,
            windowTailExchanges: 10,
            contextTrimTargetPct: $contextTrimTargetPct,
        );

        $engine = new RunEngine(
            $this->llm,
            $compiler,
            $container->get(ToolboxResolver::class),
            $this->executor,
            $window,
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            $container->get(RunGraph::class),
            ['step_budget' => 50, 'tool_retries' => 2, 'circuit_breaker' => 3],
        );

        $run = $engine->run($task);

        self::assertSame(RunStatus::Succeeded, $run->getStatus());
        self::assertGreaterThanOrEqual($rounds, \count($this->requests), 'every tool round issued a request');

        $trims = $this->countEvents($run, RunEventType::ContextTrim);

        // The oldest retained exchange, turn by turn. Its identity changing
        // between two requests that each had a front is the front moving.
        $oldest = array_map($this->oldestExchangeId(...), $this->requests);
        $frontMoves = 0;
        for ($i = 1; $i < \count($oldest); ++$i) {
            if (null !== $oldest[$i] && null !== $oldest[$i - 1] && $oldest[$i] !== $oldest[$i - 1]) {
                ++$frontMoves;
            }
        }

        $reuse = $this->reusePercents($this->requests);

        return [
            'turns' => \count($this->requests),
            'trims' => $trims,
            'frontMoves' => $frontMoves,
            'medianReuse' => $this->percentile($reuse, 0.5),
            'minReuse' => [] === $reuse ? 0.0 : min($reuse),
            'trimPayload' => $this->eventPayload($run, RunEventType::ContextTrim),
        ];
    }

    /** Fresh ledger rows and fresh stubs, so two scenarios do not share state. */
    private function resetWorld(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StreamEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->executor = $this->createMock(ToolExecutorInterface::class);
        $this->chatCount = 0;
        $this->requests = [];
    }

    /**
     * The sticky base is real checkpoint state: it must round-trip through the
     * JSON a fresh worker reloads, or recovery would silently slide the front
     * back to the head every time the carrier changed.
     */
    public function testTheStickyBaseRoundTripsThroughTheCheckpoint(): void
    {
        $state = new LoopState(contextBase: 7, contextBatch: true);

        $reloaded = LoopState::fromCheckpoint($state->toCheckpoint(), 50, 2, 3);

        self::assertSame(7, $reloaded->contextBase);
        self::assertTrue($reloaded->contextBatch);
    }

    /**
     * A checkpoint that predates the sticky base loads with a null base and
     * batching off — the pre-existing stateless shape, exactly.
     */
    public function testAPreExistingCheckpointLoadsStateless(): void
    {
        $reloaded = LoopState::fromCheckpoint(['step' => 3, 'exchanges' => []], 50, 2, 3);

        self::assertNull($reloaded->contextBase);
        self::assertFalse($reloaded->contextBatch);
    }

    /** Script N tool-call responses then a terminal message. */
    private function scriptToolRounds(int $rounds): void
    {
        $this->llm->method('chat')->willReturnCallback(
            function (array $messages) use ($rounds): LlmResponse {
                $this->requests[] = $messages;
                ++$this->chatCount;

                return $this->chatCount <= $rounds
                    ? $this->response(toolCalls: [['id' => 'c'.$this->chatCount, 'name' => 'get_weather', 'arguments' => ['location' => 'X']]])
                    : $this->response(content: 'All done.');
            },
        );
    }

    /**
     * The id of the oldest exchange still visible in a request: the first tool
     * call or tool result the messages carry. Its identity changing between
     * turns is the front moving.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function oldestExchangeId(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (isset($message['tool_calls'][0]['id'])) {
                return (string) $message['tool_calls'][0]['id'];
            }
            if (isset($message['tool_call_id'])) {
                return (string) $message['tool_call_id'];
            }
        }

        return null;
    }

    /**
     * For each request after the first, the share of the previous prompt that
     * is an unchanged prefix — the deterministic stand-in for `cached_tokens`.
     *
     * @param list<list<array<string, mixed>>> $requests
     *
     * @return list<float>
     */
    private function reusePercents(array $requests): array
    {
        $percents = [];
        for ($i = 1; $i < \count($requests); ++$i) {
            $previous = $requests[$i - 1];
            $current = $requests[$i];

            $prefixChars = 0;
            $max = min(\count($previous), \count($current));
            for ($m = 0; $m < $max; ++$m) {
                $a = (string) json_encode($previous[$m], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $b = (string) json_encode($current[$m], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($a !== $b) {
                    break;
                }
                $prefixChars += \strlen($a);
            }

            $previousChars = 0;
            foreach ($previous as $message) {
                $previousChars += \strlen((string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $percents[] = $previousChars > 0 ? 100.0 * $prefixChars / $previousChars : 0.0;
        }

        return $percents;
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $q): float
    {
        if ([] === $values) {
            return 0.0;
        }
        sort($values);
        $index = (int) floor($q * (\count($values) - 1));

        return $values[$index];
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}>|null $toolCalls
     */
    private function response(?string $content = null, ?array $toolCalls = null): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            finishReason: null === $toolCalls ? 'stop' : 'tool_calls',
            toolCalls: $toolCalls ?? [],
            usage: ['total_tokens' => 10],
            reasoningContent: null,
            durationMs: 5,
        );
    }

    private function catalogTool(string $name): Tool
    {
        $server = new McpServer('test-server', 'https://server.example/mcp', ServerProtocol::Mcp);
        $this->em->persist($server);

        $tool = new Tool($server, $name, 'test tool', [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ], [$server->getName()]);
        $this->em->persist($tool);
        $this->em->flush();

        return $tool;
    }

    /** @param list<string> $toolbox */
    private function enabledTask(array $toolbox = ['get_weather'], ToolboxMode $mode = ToolboxMode::Tags): Task
    {
        $task = new Task(
            title: 'Shedding smoke task',
            brief: 'Read many things, then stop.',
            kind: TaskKind::Run,
            toolboxMode: $mode,
            toolbox: ToolboxMode::Explicit === $mode ? $toolbox : ['test-server'],
            createdBy: TaskAuthor::User,
        );
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function countEvents(Run $run, RunEventType $type): int
    {
        $count = 0;
        foreach ($run->getEvents() as $event) {
            if ($event->getType() === $type) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return array<string, mixed> */
    private function eventPayload(Run $run, RunEventType $type): array
    {
        foreach ($run->getEvents() as $event) {
            if ($event->getType() === $type) {
                return $event->getPayload();
            }
        }

        self::fail("No {$type->value} event in ledger.");
    }
}
