<?php

declare(strict_types=1);

namespace App\Command;

use App\Chat\ChatEngine;
use App\Repository\ChatExchangeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recovery for chat exchanges: re-dispatch the reply an active exchange is
 * owed (SPEC §15).
 *
 * The counterpart of `app:run:requeue`, and for the same reason. The
 * transport redelivers a message whose worker died, but a message lost in ways
 * redelivery cannot see — the queue table purged, the database restored from a
 * backup — leaves an exchange with committed state and no carrier. The
 * exchange row is the durable queue of record; this re-derives what is owed
 * from it and dispatches.
 *
 * Safe to run at any time: the dispatch is the work committed state already
 * implies, and the claim makes an extra delivery a no-op. Meant for operators,
 * and for reflex testing — a chat is the one place where a lost carrier has a
 * human sitting in front of it, so "there is a command for this" matters more
 * here than it does for runs.
 */
#[AsCommand(
    name: 'app:chat:requeue',
    description: 'Re-derive and dispatch the replies active chat exchanges are owed (recovery).',
)]
final class ChatRequeueCommand extends Command
{
    public function __construct(
        private readonly ChatExchangeRepository $exchanges,
        private readonly ChatEngine $engine,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be dispatched, dispatch nothing')
            ->setHelp(<<<'TXT'
                Re-derives the outstanding reply for every active chat exchange
                (queued / running) and dispatches it. Healthy exchanges receive a
                duplicate delivery that the engine drops as stale.

                Use after a queue table was purged or the database was restored
                from a backup: an exchange in that state has committed state and
                nothing that will ever advance it, and a person is waiting on the
                other end of it.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $dispatched = 0;
        $skipped = 0;

        foreach ($this->exchanges->findActive() as $exchange) {
            $message = $this->engine->nextTurnMessage($exchange);

            if (null === $message) {
                ++$skipped;

                continue;
            }

            if ($dryRun) {
                $output->writeln(\sprintf(
                    'Exchange %d (chat %d, %s): would dispatch %s.',
                    $exchange->getId(),
                    $exchange->getChat()->getId(),
                    $exchange->getStatus()->value,
                    $message::class,
                ));
                ++$dispatched;

                continue;
            }

            // The engine's own dispatch path is the only writer, so recovery
            // goes through it rather than around it: same transaction, same
            // lane, same staleness rules.
            $this->engine->requeue($message);

            $output->writeln(\sprintf(
                'Exchange %d (chat %d, %s): dispatched %s.',
                $exchange->getId(),
                $exchange->getChat()->getId(),
                $exchange->getStatus()->value,
                $message::class,
            ));
            ++$dispatched;
        }

        $output->writeln($dryRun
            ? \sprintf('%d exchange(s) would be requeued, %d skipped.', $dispatched, $skipped)
            : \sprintf('%d exchange(s) requeued, %d skipped.', $dispatched, $skipped));

        if (!$dryRun && $dispatched > 0) {
            $this->logger->info('Chat requeue sweep dispatched {count} reply turn(s).', ['count' => $dispatched]);
        }

        return self::SUCCESS;
    }
}
