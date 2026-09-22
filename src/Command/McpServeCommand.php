<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Server\TaskServerFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Serves the task-loom MCP server role (SPEC §11): the task tools over
 * Streamable HTTP. No stdio, no legacy SSE.
 */
#[AsCommand(
    name: 'app:mcp:serve',
    description: 'Serve the task-loom MCP server role (task tools) over Streamable HTTP',
)]
final class McpServeCommand extends Command
{
    public function __construct(private readonly TaskServerFactory $factory)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Listen port', '8080')
            ->addOption('stateless', null, InputOption::VALUE_NONE, 'Run without MCP sessions (one JSON-RPC POST = one response)')
            ->setHelp(<<<'TXT'
                Serves the task tools (task_create, task_update, task_list,
                task_get) over MCP Streamable HTTP (SPEC §11).

                The endpoint is POST /mcp. Writes are gated (SPEC §4.3):
                every task_create and task_update persists disabled and
                lands in the human approval queue.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');
        $stateless = (bool) $input->getOption('stateless');

        $server = $this->factory->build();

        $transport = new \PhpMcp\Server\Transports\StreamableHttpServerTransport(
            host: $host,
            port: (int) $port,
            mcpPath: '/mcp',
            enableJsonResponse: true,
            stateless: $stateless,
        );

        $io->success("MCP server listening on http://{$host}:{$port}/mcp (stateless: ".($stateless ? 'yes' : 'no').')');

        // listen() blocks: binds the socket, runs the ReactPHP loop, and
        // ends cleanly when the transport closes (Ctrl-C).
        $server->listen($transport);

        return Command::SUCCESS;
    }
}
