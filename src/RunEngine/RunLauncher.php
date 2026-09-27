<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\RunRole;
use App\Entity\Task;
use App\Message\LlmTurnMessage;
use App\Message\ToolTurnMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The queue-path launch (SPEC §6, §8): create the run — a graph parent for
 * a stepped task, a standalone run otherwise — and dispatch its first turn
 * to the Messenger lanes.
 *
 * This is the one entry point shared by `app:run:now --queue` and the admin
 * UI's Run now action, so the two triggers cannot drift: same run shape,
 * same dispatch, same recovery semantics (a process that dies between the
 * run row committing and the dispatch is repaired by app:run:requeue).
 *
 * The web surface uses the queue path exclusively — a request must never
 * hold an LLM turn on the wire; the run's progress is followed in the run
 * surface, not the response.
 */
final readonly class RunLauncher
{
    public function __construct(
        private RunEngine $engine,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @throws RunLaunchException         when the created run owes no turn to dispatch
     * @throws ToolboxResolutionException when a standalone task's toolbox does not resolve
     */
    public function launch(Task $task): RunLaunch
    {
        $run = $this->engine->start($task);

        if (RunRole::Parent === $run->getRole()) {
            // beginGraph() created the parent and dispatched every root
            // step's first turn inside its creation transaction.
            return new RunLaunch($run, null);
        }

        $message = $this->engine->nextTurnMessage($run);
        if (!$message instanceof LlmTurnMessage && !$message instanceof ToolTurnMessage) {
            throw new RunLaunchException(\sprintf('Run %d was created but has no turn to dispatch.', $run->getId()));
        }

        $this->bus->dispatch($message);

        return new RunLaunch($run, $message);
    }
}
