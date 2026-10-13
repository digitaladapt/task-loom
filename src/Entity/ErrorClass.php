<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The fixed error taxonomy (SPEC §5.3) — not free text. Run reports
 * aggregate by class so "malformed tool calls" vs "server 500s" vs
 * "context overflow" are distinguishable at a glance.
 */
enum ErrorClass: string
{
    case InvalidArguments = 'invalid_arguments';
    case ToolNotFound = 'tool_not_found';
    case ServerError = 'server_error';
    case ServerTimeout = 'server_timeout';
    case LlmError = 'llm_error';
    case LlmMalformedResponse = 'llm_malformed_response';
    case ContextExhausted = 'context_exhausted';
    case BudgetExceeded = 'budget_exceeded';
    case Unknown = 'unknown';

    // The stream-cut classes (SPEC §5.3, the streaming-capture change).
    // Each names a different operator story, which is the point: "why did
    // it take forever" is answerable from the class alone — a loop is the
    // model repeating itself, first-token is prefill/queue, silence is a
    // wedged server, duration is a run that asked for too much output.
    // The single legacy `server_timeout` could not tell these apart.
    case LlmLoopDetected = 'llm_loop_detected';
    case LlmFirstTokenTimeout = 'llm_first_token_timeout';
    case LlmSilenceTimeout = 'llm_silence_timeout';
    case LlmDurationExceeded = 'llm_duration_exceeded';
}
