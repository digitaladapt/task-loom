<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Repository\McpServerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:server',
    description: 'Manage registered MCP/OpenAPI tool servers (add, list, remove)',
)]
final class CatalogServerCommand extends Command
{
    public function __construct(private readonly McpServerRepository $servers)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'add | list | remove')
            ->addArgument('name', InputArgument::OPTIONAL, 'Server name (add/remove)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Server URL (add)')
            ->addOption('protocol', 'p', InputOption::VALUE_REQUIRED, 'mcp | openapi (add)', 'mcp')
            ->addOption('cred-var', null, InputOption::VALUE_REQUIRED, 'Env var NAME holding the credential (add; never the value itself)', null)
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Register the server disabled (add)')
            ->setHelp(<<<'TXT'
                Registers and manages tool servers in the catalog registry.

                add:
                  app:catalog:server add weather --url=http://localhost:8081/mcp --protocol=mcp
                  app:catalog:server add transactions --url=https://api.example.com/openapi.json --protocol=openapi --cred-var=TRANSACTIONS_API_KEY

                The --cred-var option takes the NAME of an environment variable
                (e.g. WEATHER_API_KEY), never the secret value. The value is
                resolved at call time and scrubbed from all traces.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        return match ($action) {
            'add' => $this->add($input, $io),
            'list' => $this->list($io),
            'remove' => $this->remove($input, $io),
            default => $this->unknownAction($action, $io),
        };
    }

    private function add(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('name');
        $url = $input->getOption('url');
        $protocolRaw = $input->getOption('protocol');
        $credVar = $input->getOption('cred-var');
        $disabled = $input->getOption('disabled');

        if (!\is_string($name) || '' === $name) {
            $io->error('Server name is required for add.');

            return Command::FAILURE;
        }

        if (!\is_string($url) || '' === $url) {
            $io->error('--url is required for add.');

            return Command::FAILURE;
        }

        $protocol = ServerProtocol::tryFrom(\is_string($protocolRaw) ? $protocolRaw : '');
        if (null === $protocol) {
            $io->error(\sprintf('Unknown protocol "%s" (mcp | openapi).', $protocolRaw));

            return Command::FAILURE;
        }

        if (null !== $this->servers->findOneBy(['name' => $name])) {
            $io->error(\sprintf('A server named "%s" is already registered.', $name));

            return Command::FAILURE;
        }

        $server = new McpServer($name, $url, $protocol, \is_string($credVar) && '' !== $credVar ? $credVar : null);
        if ($disabled) {
            $server->setEnabled(false);
        }
        $this->servers->save($server);

        $io->success(\sprintf('Registered server "%s" (%s, %s).%s', $name, $protocol->value, $url, $disabled ? ' (disabled)' : ''));

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $all = $this->servers->findBy([], ['name' => 'ASC']);
        if ([] === $all) {
            $io->note('No servers registered yet. Add one with app:catalog:server add.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Name', 'Protocol', 'URL', 'Enabled', 'Tools', 'Last sync', 'Status'],
            array_map(static fn (McpServer $s) => [
                $s->getName(),
                $s->getProtocol()->value,
                $s->getUrl(),
                $s->isEnabled() ? 'yes' : 'no',
                (string) \count($s->getTools()),
                $s->getLastSyncedAt()?->format('Y-m-d H:i:s') ?? '—',
                $s->getLastSyncStatus() ?? '—',
            ], $all),
        );

        return Command::SUCCESS;
    }

    private function remove(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('name');
        if (!\is_string($name) || '' === $name) {
            $io->error('Server name is required for remove.');

            return Command::FAILURE;
        }

        $server = $this->servers->findOneBy(['name' => $name]);
        if (null === $server) {
            $io->error(\sprintf('No server named "%s" is registered.', $name));

            return Command::FAILURE;
        }

        $toolCount = \count($server->getTools());
        $this->servers->remove($server);

        $io->success(\sprintf('Removed server "%s" and its %d tool(s).', $name, $toolCount));

        return Command::SUCCESS;
    }

    private function unknownAction(mixed $action, SymfonyStyle $io): int
    {
        $io->error(\sprintf('Unknown action "%s" (add | list | remove).', $action));

        return Command::INVALID;
    }
}
