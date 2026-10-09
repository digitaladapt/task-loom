<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The minimal shape shared by everything a run can call: a name, a
 * description, and the JSON Schema its arguments are validated against
 * (SPEC §5.1).
 *
 * Implemented by the catalog's {@see Tool} and by the harness's own session
 * tools (`App\Session\SessionTool`, docs/design/SESSION_TASKS.md §5, build
 * order step 3). Prompt compilation, the OpenAI tool descriptors, and the
 * frozen toolbox snapshot all speak this interface, so "a session's toolbox
 * is its MCP tools plus the harness's own" is one list of one shape — there
 * is no second path where a harness tool could drift out of the prompt, off
 * the wire, or out of the frozen snapshot.
 */
interface ToolDefinition
{
    /** The name the model calls, as it appears in the toolbox and the snapshot. */
    public function getName(): string;

    /** One line of guidance for the model; may be null for a discovered tool without one. */
    public function getDescription(): ?string;

    /**
     * JSON Schema describing the tool's arguments.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array;
}
