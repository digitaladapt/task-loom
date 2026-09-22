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
}
