<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Contract for the run engine's LLM dependency (the OpenAI-compatible
 * client in production; stubs in tests).
 */
interface LlmClientInterface
{
    /**
     * One chat completion turn.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     *
     * @throws LlmRequestException on transport/HTTP/auth/parse failures
     */
    public function chat(array $messages, array $tools = []): LlmResponse;
}
