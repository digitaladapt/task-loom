<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\RunEngine\RunEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run-now (SPEC §8): the only task trigger in v1. Synchronous — the
 * chunk-4 Messenger handler will call the same RunEngine::run().
 */
#[AsCommand(
    name: 'app:run:now',
    description: 'Run a task now (v1\'s only trigger: synchronous, with the full attempt ledger).',
)]
final class RunNowCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly RunEngine $engine,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::REQUIRED, 'ID of the enabled task to run')
            ->setHelp(<<<'TXT'
                Runs the task to completion, synchronously. Every LLM request,
                tool call, validation error, retry, and the completion
                declaration are persisted as RunEvent rows (the attempt ledger).

                Exit code 0 on succeeded; 1 on any non-succeeded terminal state
                (incomplete / failed / needs_attention), so scripts can react.
                TXT);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = (int) $input->getArgument('task-id');

        $task = $this->tasks->find($taskId);
        if (!$task instanceof Task) {
            $output->writeln(\sprintf('<error>No task with id %d.</error>', $taskId));

            return self::FAILURE;
        }

        if (!$task->isEnabled()) {
            $output->writeln(\sprintf('<error>Task %d ("%s") is not enabled.</error>', $taskId, $task->getTitle()));

            return self::FAILURE;
        }

        $run = $this->engine->run($task);

        $output->writeln(\sprintf('Run %d: %s (steps: %d)', $run->getId(), $run->getStatus()->value, $run->getStepCount()));

        return RunEngineTerminal::toExitCode($run);
    }
}
