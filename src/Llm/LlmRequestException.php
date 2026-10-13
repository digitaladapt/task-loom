<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\ErrorClass;

/**
 * A failed LLM request, already classified for the attempt ledger
 * (SPEC §5.3): llm_error, llm_malformed_response, one of the stream-cut
 * classes, or unknown.
 *
 * A stream that was cut after producing output does NOT throw — the client
 * returns the partial response with its {@see LlmStop} attached, because
 * for chat a partial answer is an answer. This exception is for the cases
 * where there is nothing to show: a transport failure before output, an
 * HTTP error, a malformed body, or a cut with nothing captured.
 */
final class LlmRequestException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly ErrorClass $errorClass,
    ) {
        parent::__construct($message);
    }

    public static function transport(\Throwable $e): self
    {
        return new self('LLM transport failure: '.$e->getMessage(), ErrorClass::LlmError);
    }

    public static function httpError(int $status, string $detail): self
    {
        return new self(\sprintf('LLM endpoint returned HTTP %d: %s', $status, $detail), ErrorClass::LlmError);
    }

    public static function malformed(\Throwable $e): self
    {
        return new self('LLM response malformed: '.$e->getMessage(), ErrorClass::LlmMalformedResponse);
    }

    /**
     * A guard cut the stream before any output could be captured — the cut
     * carries its own class so the ledger says "no output at all within 90s"
     * rather than a bare timeout.
     */
    public static function streamStop(LlmStop $stop): self
    {
        return new self('LLM stream stopped: '.$stop->detail, $stop->errorClass());
    }
}
