<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Run;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\SessionKindUnsupportedException;
use App\Entity\Task;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\RunEngine;
use App\RunEngine\RunLauncher;
use App\RunEngine\RunLaunchException;
use App\RunEngine\ToolboxResolutionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run-now (SPEC §8): the manual task trigger. Scheduled tasks fire through
 * the scheduler tick instead (SPEC §14).
 *
 * Two modes, one gate:
 *   - default: synchronous — drives the turn cores inline and reports the
 *     terminal status (exit 0 only on succeeded).
 *   - --queue: dispatch — creates the run and drops its first turn on the
 *     `llm` lane; workers (`messenger:consume llm tools`) do the driving.
 *     Exit 0 means accepted for execution; the run's outcome is followed
 *     through the ledger, not this exit code.
 */
#[AsCommand(
    name: 'app:run:now',
    description: 'Run a task now: synchronously, or dispatched to the worker lanes with --queue.',
)]
final class RunNowCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly RunRepository $runs,
        private readonly RunEngine $engine,
        private readonly RunLauncher $launcher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::REQUIRED, 'ID of the enabled task to run')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Dispatch to the Messenger lanes instead of running synchronously')
            ->setHelp(<<<'TXT'
                Runs the task. By default synchronously, to completion: every LLM
                request, tool call, validation error, retry, and the completion
                declaration are persisted as RunEvent rows (the attempt ledger).
                Exit code 0 on succeeded; 1 on any non-succeeded terminal state
                (incomplete / failed / needs_attention), so scripts can react.

                With --queue the run is created and its first LLM turn is
                dispatched to the `llm` lane; start workers with

                    php bin/console messenger:consume llm tools

                (one [llm] worker per concurrent LLM request — see README,
                "Concurrency"). Exit code 0 means the run was accepted for
                execution; follow its progress in the ledger.
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

        if ($input->getOption('queue')) {
            return $this->dispatch($task, $output);
        }

        try {
            $run = $this->engine->run($task);
        } catch (SessionKindUnsupportedException $e) {
            // docs/design/SESSION_TASKS.md, build order step 1: the engine
            // refuses the kind before any run row exists.
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if (RunRole::Parent === $run->getRole()) {
            $children = $this->runs->findChildren($run);
            $stepRuns = \count(array_filter($children, static fn (Run $child): bool => RunRole::Step === $child->getRole()));
            $output->writeln(\sprintf(
                'Run %d: %s (%d step run(s), %d child run(s) total)',
                $run->getId(),
                $run->getStatus()->value,
                $stepRuns,
                \count($children),
            ));
        } else {
            $output->writeln(\sprintf('Run %d: %s (steps: %d)', $run->getId(), $run->getStatus()->value, $run->getStepCount()));
        }

        return RunEngineTerminal::toExitCode($run);
    }

    /**
     * Async path: the shared queue-path launch (SPEC §6) — the same entry
     * point the admin UI's Run now action uses, so the two triggers cannot
     * drift. The run row is the queue — if this process dies after the run
     * is saved but before the dispatch, app:run:requeue recovers it.
     *
     * A stepped task is a run GRAPH (SPEC §13.3): start() already created
     * the parent and dispatched every root step's first turn inside its
     * creation transaction, so there is no single "first message" here.
     */
    private function dispatch(Task $task, OutputInterface $output): int
    {
        try {
            $launch = $this->launcher->launch($task);
        } catch (ToolboxResolutionException|RunLaunchException|SessionKindUnsupportedException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $run = $launch->run;
        if (RunRole::Parent === $run->getRole()) {
            $queued = \count(array_filter($this->runs->findChildren($run), static fn (Run $child): bool => RunStatus::Queued === $child->getStatus()));
            $output->writeln(\sprintf('Run %d: queued — %d step run(s) on the "llm" lane.', $run->getId(), $queued));
            $output->writeln('Consume with: php bin/console messenger:consume llm tools');

            return self::SUCCESS;
        }

        $output->writeln(\sprintf('Run %d: queued — %s dispatched to the "%s" lane.', $run->getId(), $launch->messageClass(), $launch->lane()));
        $output->writeln('Consume with: php bin/console messenger:consume llm tools');

        return self::SUCCESS;
    }
}
