<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\SessionTurnMessage;
use App\RunEngine\RunEngine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One delivered LLM turn of a session slice — the `session` lane's
 * counterpart to `LlmTurnHandler`.
 *
 * Deliberately a pass-through, exactly as `LlmTurnHandler` is: the engine
 * does the work and the atomicity lives there, not here. A crash between
 * the engine returning and the worker acking leaves a duplicate, which the
 * engine drops as stale against the claim and the committed state.
 *
 * A session delivery is the lowest priority the fleet serves: the LLM
 * workers consume `chat llm session` and messenger:consume is
 * strict-priority across its receivers, so this handler is reached only
 * when no chat reply and no task turn is waiting (docs/design/
 * SESSION_TASKS.md §7.1). The delivery label rides into the engine so its
 * log lines name the message an operator is actually chasing.
 */
#[AsMessageHandler]
final readonly class SessionTurnHandler
{
    public function __construct(
        private RunEngine $engine,
    ) {
    }

    public function __invoke(SessionTurnMessage $message): void
    {
        $this->engine->llmTurn($message->runId, $message->step, delivery: 'SessionTurnMessage');
    }
}
