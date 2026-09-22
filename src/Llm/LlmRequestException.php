<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\ErrorClass;

/**
 * A failed LLM request, already classified for the attempt ledger
 * (SPEC §5.3): llm_error, llm_malformed_response, or unknown.
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
}
