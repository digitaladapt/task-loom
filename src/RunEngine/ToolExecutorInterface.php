<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Tool;

/**
 * Contract for tool-call validation + dispatch (MCP Streamable HTTP in
 * production; stubs in tests).
 */
interface ToolExecutorInterface
{
    /**
     * Validate arguments against the tool's JSON Schema (SPEC §5.1).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string> validation errors; empty = valid
     */
    public function validate(Tool $tool, array $arguments): array;

    /**
     * Execute a tool call against its server.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws ToolExecutionException classified for the ledger
     */
    public function execute(Tool $tool, array $arguments): array;
}
