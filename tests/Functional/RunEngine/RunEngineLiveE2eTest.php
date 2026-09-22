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
use App\Llm\LlmClient;
use App\Repository\RunRepository;
use App\RunEngine\PromptCompiler;
use App\RunEngine\RunEngine;
use App\RunEngine\ToolboxResolver;
use App\RunEngine\ToolExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * LIVE end-to-end run (SPEC §3, §5): a real LLM (OpenAI-compatible
 * endpoint), a real MCP server (Streamable HTTP), and the real Doctrine
 * attempt ledger — nothing stubbed.
 *
 * Network-gated: the suite is skipped unless the operator provides the
 * live endpoint via environment variables, so CI (which has no network
 * credentials) never runs it:
 *
 *   TASKLOOM_E2E_LLM_BASE_URL=https://llm.devgnome.com \
 *   TASKLOOM_E2E_LLM_MODEL=K2Horizon-36B-A4B-InfinimindCreations \
 *   TASKLOOM_E2E_LLM_API_KEY=... \
 *   php vendor/bin/phpunit tests/Functional/RunEngine/RunEngineLiveE2eTest.php
 *
 * TASKLOOM_E2E_MCP_URL (default https://mcp.devgnome.com/mcp) must expose
 * the dependency-free `echo` tool — the run drives a real tools/call
 * round-trip, no external weather APIs involved.
 */
final class RunEngineLiveE2eTest extends KernelTestCase
{
    private const string ECHO_MESSAGE = 'loom-e2e-ping';

    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if ('' === (string) ($_SERVER['TASKLOOM_E2E_LLM_BASE_URL'] ?? $_ENV['TASKLOOM_E2E_LLM_BASE_URL'] ?? '')) {
            self::markTestSkipped('Live e2e disabled: set TASKLOOM_E2E_LLM_BASE_URL (and friends) to run it.');
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();
    }

    public function testLiveRunCompletesWithRealToolCall(): void
    {
        $baseUrl = $this->e2e('TASKLOOM_E2E_LLM_BASE_URL');
        $model = $this->e2e('TASKLOOM_E2E_LLM_MODEL');
        $apiKey = $this->e2e('TASKLOOM_E2E_LLM_API_KEY');
        $mcpUrl = $this->e2e('TASKLOOM_E2E_MCP_URL', 'https://mcp.devgnome.com/mcp');

        $server = new McpServer('context-shuttle', $mcpUrl, ServerProtocol::Mcp);
        $this->em->persist($server);

        // The real `echo` schema from the live server (dependency-free tool).
        $tool = new Tool($server, 'echo', 'Echo the provided message back.', [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'The text to echo back.'],
                'style' => [
                    'type' => 'string',
                    'description' => 'Optional case transform: "upper" or "lower". Default: none.',
                    'enum' => ['upper', 'lower'],
                ],
            ],
            'required' => ['message'],
        ], [$server->getName()]);
        $this->em->persist($tool);

        $task = new Task(
            title: 'Live e2e: echo round-trip',
            brief: 'Call the echo tool exactly once with the message "'.self::ECHO_MESSAGE.'", '
                .'then report what the tool returned verbatim in one sentence.',
            kind: TaskKind::Run,
            toolboxMode: ToolboxMode::Explicit,
            toolbox: ['echo'],
            createdBy: TaskAuthor::User,
        );
        $task->enable();
        $this->em->persist($task);
        $this->em->flush();
        $this->em->clear();

        $container = static::getContainer();
        $engine = new RunEngine(
            new LlmClient(HttpClient::create(), $baseUrl, $model, $apiKey, 300),
            $container->get(PromptCompiler::class),
            $container->get(ToolboxResolver::class),
            $container->get(ToolExecutor::class),
            $container->get(ContextWindow::class),
            $container->get(RunRepository::class),
            $this->em,
            new NullLogger(),
            ['step_budget' => 8, 'tool_retries' => 1, 'circuit_breaker' => 3],
        );

        $freshTask = $this->em->find(Task::class, $task->getId());
        self::assertNotNull($freshTask);

        $run = $engine->run($freshTask);

        // Terminal state and the full ledger shape (SPEC §5.3).
        self::assertSame(RunStatus::Succeeded, $run->getStatus(), $this->ledgerDump($run));
        self::assertGreaterThanOrEqual(2, $run->getStepCount());

        $types = [];
        foreach ($run->getEvents() as $event) {
            $types[] = $event->getType();
        }
        self::assertContains(RunEventType::ToolCall, $types, $this->ledgerDump($run));
        self::assertContains(RunEventType::ToolResult, $types, $this->ledgerDump($run));
        self::assertContains(RunEventType::Completion, $types, $this->ledgerDump($run));

        // The real MCP round-trip happened and the result fed back to the model.
        $result = $this->payload($run, RunEventType::ToolResult);
        self::assertFalse((bool) $result['isError']);
        self::assertStringContainsString(self::ECHO_MESSAGE, (string) $result['content']);

        $completion = $this->payload($run, RunEventType::Completion);
        self::assertNotSame('', (string) $completion['result']);
    }

    // --------------------------------------------------------------- helpers

    private function e2e(string $name, string $default = ''): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    /** @return array<string, mixed> */
    private function payload(Run $run, RunEventType $type): array
    {
        foreach ($run->getEvents() as $event) {
            if ($event->getType() === $type) {
                return $event->getPayload();
            }
        }

        self::fail("No {$type->value} event in ledger.");
    }

    private function ledgerDump(Run $run): string
    {
        $lines = [];
        foreach ($run->getEvents() as $event) {
            $lines[] = $event->getType()->value.': '.json_encode($event->getPayload());
        }

        return "Run {$run->getId()} ({$run->getStatus()->value}) ledger:\n".implode("\n", $lines);
    }
}
