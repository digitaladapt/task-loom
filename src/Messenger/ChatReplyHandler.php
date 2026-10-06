<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Chat\ChatEngine;
use App\Message\ChatReplyMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One delivered reply turn of a chat exchange.
 *
 * Deliberately a pass-through, exactly as `LlmTurnHandler` is: the engine does
 * the work and the atomicity lives there, not here. A crash between the engine
 * returning and the worker acking leaves a duplicate, which the engine drops
 * as stale against the claim and the committed state.
 *
 * This class is the chat half of the concurrency unit
 * `TASKLOOM_LLM_MAX_CONCURRENCY` counts: a `chat` lane delivery that reaches
 * the LLM is one request on the wire, and the total in flight is still bounded
 * by the number of LLM workers, because the chat lane is drained by the same
 * workers — just first.
 */
#[AsMessageHandler]
final readonly class ChatReplyHandler
{
    public function __construct(
        private ChatEngine $engine,
    ) {
    }

    public function __invoke(ChatReplyMessage $message): void
    {
        $this->engine->reply($message->exchangeId);
    }
}
