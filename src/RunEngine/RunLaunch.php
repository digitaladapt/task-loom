<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Run;
use App\Message\LlmTurnMessage;
use App\Message\ToolTurnMessage;

/**
 * The outcome of a queue-path launch (RunLauncher::launch): the created run
 * and the first turn dispatched for it. `message` is null for a graph
 * parent — beginGraph() dispatched every root step's turn inside its
 * creation transaction, so there is nothing left for the caller to carry.
 */
final readonly class RunLaunch
{
    public function __construct(
        public Run $run,
        public LlmTurnMessage|ToolTurnMessage|null $message,
    ) {
    }

    /** The lane the first turn was dispatched to, or null for a parent. */
    public function lane(): ?string
    {
        return match (true) {
            $this->message instanceof LlmTurnMessage => 'llm',
            $this->message instanceof ToolTurnMessage => 'tools',
            default => null,
        };
    }

    /** The dispatched message's class, for the operator-facing report. */
    public function messageClass(): ?string
    {
        return null === $this->message ? null : $this->message::class;
    }
}
