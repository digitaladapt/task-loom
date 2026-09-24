<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\ToolTurnMessage;
use App\RunEngine\RunEngine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One delivered tool turn of a run: executes the model's pending tool calls
 * (resuming at the persisted position if a previous worker died mid-turn),
 * then re-enqueues the run's next LLM turn at the back of the LLM lane —
 * the FIFO fairness point. That enqueue happens INSIDE the engine's commit
 * transaction, together with the exchange checkpoint (SPEC §6); this
 * handler is a pass-through.
 *
 * A duplicate delivery (redelivery, manual requeue racing a live carrier)
 * finds nothing pending and is dropped by the engine as stale; a delivered
 * turn whose carrier was lost is recovered by app:run:requeue.
 */
#[AsMessageHandler]
final readonly class ToolTurnHandler
{
    public function __construct(
        private RunEngine $engine,
    ) {
    }

    public function __invoke(ToolTurnMessage $message): void
    {
        $this->engine->toolTurn($message->runId, $message->step);
    }
}
