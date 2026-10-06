<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Chat\ChatEngine;
use App\Message\ChatToolTurnMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One delivered tool turn of a chat exchange.
 *
 * The same shape as `ToolTurnHandler` and `ChatReplyHandler`: a pass-through,
 * because the engine owns the transaction that commits results with the
 * successor message. A duplicate delivery finds nothing pending and is dropped
 * as stale.
 */
#[AsMessageHandler]
final readonly class ChatToolTurnHandler
{
    public function __construct(
        private ChatEngine $engine,
    ) {
    }

    public function __invoke(ChatToolTurnMessage $message): void
    {
        $this->engine->toolTurn($message->exchangeId);
    }
}
