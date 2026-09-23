<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\LlmTurnMessage;
use App\RunEngine\RunEngine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One delivered LLM turn of a run.
 *
 * The engine does the work — and, in async mode, enqueues the successor
 * turn (tool lane, or the next LLM turn for the sync-repair case) INSIDE
 * the same database transaction that commits the turn's state. The
 * atomicity lives there, not here; this handler is deliberately a
 * pass-through. A crash between the engine returning and the worker
 * acking leaves a duplicate, which the engine drops as stale.
 *
 * This class is the concurrency unit the TASKLOOM_LLM_MAX_CONCURRENCY
 * semaphore counts: N `messenger:consume llm` workers mean at most N LLM
 * requests on the wire (SPEC §6).
 */
#[AsMessageHandler]
final readonly class LlmTurnHandler
{
    public function __construct(
        private RunEngine $engine,
    ) {
    }

    public function __invoke(LlmTurnMessage $message): void
    {
        $this->engine->llmTurn($message->runId, $message->step);
    }
}
