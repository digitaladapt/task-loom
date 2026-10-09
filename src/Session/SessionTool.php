<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\ToolDefinition;

/**
 * The harness's own session tools (docs/design/SESSION_TASKS.md §5, build
 * order step 3) — task-loom's first built-in tools: the ones the harness
 * implements itself rather than discovering from an MCP server.
 *
 * A fixed enum, deliberately: these are a closed vocabulary, not a catalog.
 * The session's write surface is exactly what this enum expresses — the
 * model cannot call a tool into existence, cannot delete a note, cannot edit
 * a history, cannot demote or pin (those are operator actions, §6; the
 * asymmetry is the design). Adding a capability is a new case here, in
 * review, beside the schema that describes it.
 *
 * The model's writes are **append-and-replace only**:
 *
 * - {@see self::Note} appends one note (hot).
 * - {@see self::Objective} sets the objective (§3.2: replacement; the
 *   superseded text is kept).
 *
 * `session_declare` — the call that ends a slice — joins this enum with the
 * slice engine (build order step 4).
 *
 * These tools are injected into a session's frozen toolbox by the resolver
 * (`App\RunEngine\ToolboxResolver`: MCP tools + these), never declared by
 * the operator. Their names are reserved: a catalog tool of the same name is
 * refused at resolution rather than shadowed at dispatch.
 */
enum SessionTool: string implements ToolDefinition
{
    /** Append one carried note — "where things are", for the model's future self. */
    case Note = 'session_note';

    /** Set the current objective — "what this is trying to achieve now". */
    case Objective = 'session_objective';

    /**
     * The harness's reserved tool names — the refusal set a session's
     * catalog resolution checks against (§5): a catalog tool carrying one of
     * these is refused at resolution rather than shadowed at dispatch.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $tool): string => $tool->value, self::cases());
    }

    #[\Override]
    public function getName(): string
    {
        return $this->value;
    }

    #[\Override]
    public function getDescription(): string
    {
        return match ($this) {
            self::Note => 'Save one thing for your future self: the harness carries notes forward and injects them into every future request. Write state, not narrative — what you learned, decided, or what to do next. Notes are visible to, and editable by, the operator.',
            self::Objective => 'Set the current objective — the aim the work ahead serves. Replaces the previous objective; the replaced text is kept, and the operator can inspect or revert it. Keep it current as the work moves.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => match ($this) {
                        self::Note => 'The note — one thing worth carrying forward.',
                        self::Objective => 'The objective — what this session is trying to achieve now.',
                    },
                ],
            ],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }
}
