<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The attempt ledger's event types (SPEC §5.3) — a fixed vocabulary, never
 * free text.
 */
enum RunEventType: string
{
    case LlmRequest = 'llm_request';
    case LlmResponse = 'llm_response';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case ToolValidationError = 'tool_validation_error';
    case ToolRetry = 'tool_retry';
    case CircuitBreaker = 'circuit_breaker';
    case ContextTrim = 'context_trim';
    case Checkpoint = 'checkpoint';
    case Completion = 'completion';

    /**
     * A slice settled undeclared and was judged a continuation
     * (docs/design/SESSION_ENDINGS.md §4): the typed marker that lets the
     * digest (and any surface) count the silent-settlement pattern apart
     * from ordinary completions. Carries the streak and its bound.
     */
    case Undeclared = 'undeclared';

    case Failure = 'failure';
}
