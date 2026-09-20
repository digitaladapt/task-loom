<?php

declare(strict_types=1);

namespace App\Command;

use App\Toolbox\CatalogSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:sync',
    description: 'Synchronize the tool catalog from registered servers',
)]
final class CatalogSyncCommand extends Command
{
    public function __construct(private readonly CatalogSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'Sync only this server (by name); omit for all enabled servers')
            ->setHelp(<<<'TXT'
                Synchronizes the tool catalog from all enabled servers (SPEC §7):
                discovered tools are merged with pin-over-discovered precedence,
                drift is logged, and a down server never wipes known tools.

                One bad server never blocks the others — each syncs in its own
                transaction and records its own outcome on the server row.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = $input->getArgument('server');

        if (null !== $serverName) {
            $server = $this->synchronizer->findServerByName($serverName);
            if (null === $server) {
                $io->error(\sprintf('No server named "%s" is registered.', $serverName));

                return Command::FAILURE;
            }
            $results = [$this->synchronizer->syncServer($server)];
        } else {
            $results = $this->synchronizer->syncAll();
        }

        $io->table(
            ['Server', 'Outcome', 'Created', 'Updated', 'Drift', 'Message'],
            array_map(static fn ($r) => [
                $r->server->getName(),
                $r->ok ? 'ok' : 'FAILED',
                (string) $r->created,
                (string) $r->updated,
                (string) $r->drifted,
                $r->message,
            ], $results),
        );

        // Sync failure is a warning, not an error: the catalog keeps serving
        // known tools (SPEC §7). Only an unknown server name fails the command.
        return Command::SUCCESS;
    }
}
