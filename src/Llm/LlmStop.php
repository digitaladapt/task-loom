<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\ErrorClass;

/**
 * The story of a generation that ended before the endpoint finished it,
 * carried on the {@see LlmResponse} it cut short.
 *
 * `detail` is the operator-facing sentence: it names the threshold that was
 * crossed and the knob that sets it, because "why did it take forever" is the
 * question this whole mechanism exists to answer, and "it took longer than 90
 * seconds (TASKLOOM_LLM_FIRST_TOKEN_TIMEOUT)" is the answer. `droppedToolCalls`
 * counts tool calls the cut caught mid-arguments: they were discarded rather
 * than dispatched half-formed — a partial call is not a call.
 */
final readonly class LlmStop
{
    public function __construct(
        public LlmStopReason $reason,
        public string $detail,
        public int $droppedToolCalls = 0,
    ) {
    }

    public function errorClass(): ErrorClass
    {
        return $this->reason->errorClass();
    }

    /**
     * The ledger shape: what stopped it, why, and what the cut discarded.
     *
     * @return array{reason: string, detail: string, droppedToolCalls: int}
     */
    public function toPayload(): array
    {
        return [
            'reason' => $this->reason->value,
            'detail' => $this->detail,
            'droppedToolCalls' => $this->droppedToolCalls,
        ];
    }
}
