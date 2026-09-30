<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\RunDigest;
use App\Entity\Run;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The reviewer's digest (SPEC §10) at a terminal.
 *
 * Same RunDigest the MCP tools call, so this is both a debugging aid for
 * that surface and a usable entry point in its own right: a cron job, a
 * pre-commit sanity check, or a human asking "why was the briefing slow
 * this morning?" without an LLM in the loop at all.
 *
 * Exit code 0 whenever a digest was produced, whatever the reviewed run's
 * own status was — this command reports, it does not judge. A task with no
 * settled run still produces a digest, whose notes say so; exit 1 is for
 * nothing to report at all (unknown task, a run id from another task, an
 * empty ledger read).
 */
#[AsCommand(
    name: 'app:run:review',
    description: 'Digest a task\'s latest settled run: tool-call repetition, retries, errors, cost, artifact (SPEC §10).',
)]
final class RunReviewCommand extends Command
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly RunRepository $runs,
        private readonly RunDigest $digest,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::REQUIRED, 'ID of the task to review')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Review this run instead of the newest settled one')
            ->addOption('history', null, InputOption::VALUE_REQUIRED, 'Include this run plus the N-1 settled runs before it as a trend')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Character budget for the digest', (string) RunDigest::DEFAULT_REVIEW_BUDGET)
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print the JSON')
            ->addOption('read-log', null, InputOption::VALUE_REQUIRED, 'Instead of the digest, read raw ledger entries: a comma-separated list of artifact, errors, tool_args, tool_results, thinking, prompt')
            ->setHelp(<<<'TXT'
                Prints what a task's latest settled run actually did — the same
                digest the run_review MCP tool returns (SPEC §10).

                The digest is deterministic and contains no LLM: tool-call
                repetition, identical repeated results, retries, the error
                rollup, token spend, and the completion artifact. It is the raw
                material for a reviewer (human or agent) deciding whether a
                task's brief or toolbox needs tightening.

                Defaults to the newest SETTLED run: an in-flight run is not
                reviewable, and an abandoned one (incomplete, no completion
                declaration) is not evidence about the task's normal behaviour.
                --run-id reviews a specific run anyway.

                --read-log switches surfaces: it reads the ledger raw, in the
                kinds you name, and always reports what its budget left out.

                Examples:

                    php bin/console app:run:review 33
                    php bin/console app:run:review 33 --history 5 --pretty
                    php bin/console app:run:review 33 --read-log artifact,errors
                    php bin/console app:run:review 33 --run-id 42 --read-log thinking
                TXT)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = (int) $input->getArgument('task-id');
        $task = $this->tasks->find($taskId);
        if (!$task instanceof Task) {
            $output->writeln(\sprintf('<error>No task with id %d.</error>', $taskId));

            return Command::FAILURE;
        }

        try {
            $target = $this->resolveRun($task, $input);
            $isReadLog = null !== $input->getOption('read-log');

            // --read-log needs a concrete run up front (the digest is not
            // choosing one for us on that path); the digest path may pass
            // null and pick the newest settled run itself.
            if ($isReadLog) {
                $target ??= $this->latestSettled($task);
                if (null === $target) {
                    $output->writeln('<error>This task has no settled run to read.</error>');

                    return Command::FAILURE;
                }
            }

            $result = $isReadLog
                ? $this->digest->readLog($target, $this->include($input))
                : $this->digest->review($task, $target, $this->budget($input), $this->history($input));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        if ($input->getOption('pretty')) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        $output->writeln((string) json_encode($result, $flags));

        return Command::SUCCESS;
    }

    /**
     * The --run-id run, validated against the task. Null means "let the
     * digest choose the newest settled run".
     *
     * A run id belonging to a different task is refused rather than
     * silently digested: a confident, wrong review is worse than an error.
     */
    private function resolveRun(Task $task, InputInterface $input): ?Run
    {
        $runId = $input->getOption('run-id');
        if (null === $runId) {
            return null;
        }

        $run = $this->runs->find((int) $runId);
        if (!$run instanceof Run) {
            throw new \InvalidArgumentException(\sprintf('No run with id %s.', $runId));
        }

        if ($run->getTask()->getId() !== $task->getId()) {
            throw new \InvalidArgumentException(\sprintf('Run %d belongs to task %s, not task %d.', (int) $run->getId(), (string) $run->getTask()->getId(), (int) $task->getId()));
        }

        return $run;
    }

    /**
     * The newest settled run — the same default the digest applies, for the
     * --read-log path where the digest is not choosing for us.
     *
     * Incomplete runs are skipped for the same reason the digest skips
     * them: a run that stopped without declaring a completion is an
     * abandoned artifact, not evidence about the task's normal behaviour.
     */
    private function latestSettled(Task $task): ?Run
    {
        foreach ($this->runs->findForTask($task) as $candidate) {
            if ($candidate->isTerminal() && RunStatus::Incomplete !== $candidate->getStatus()) {
                return $candidate;
            }
        }

        return null;
    }

    private function budget(InputInterface $input): int
    {
        return (int) $input->getOption('budget');
    }

    private function history(InputInterface $input): ?int
    {
        $history = $input->getOption('history');

        return null === $history ? null : (int) $history;
    }

    /**
     * @return list<string>
     */
    private function include(InputInterface $input): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', (string) $input->getOption('read-log'))),
            static fn (string $bucket): bool => '' !== $bucket,
        ));
    }
}
