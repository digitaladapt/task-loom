<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\SessionDeclarationOutcome;
use App\Entity\ToolDefinition;

/**
 * The harness's own session tools (docs/design/SESSION_TASKS.md §5, build
 * order steps 3–4) — task-loom's built-in tools: the ones the harness
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
 * And exactly one call ends a slice:
 *
 * - {@see self::Declare} declares the slice's outcome (§8) — the first tool
 *   call that ends a run. Unlike the writes above it is never dispatched
 *   through {@see SessionToolRunner}: the engine validates it, settles the
 *   slice in the commit that records the declaration, and (for a milestone)
 *   launches the next slice beside it. It must be **alone in its round**
 *   (§5) — a round that carries it beside other calls is refused whole and
 *   fed back, because the settle path must have no ambiguity about which
 *   calls ran.
 *
 *   The case here is the vocabulary's canonical, UNFILTERED rendering —
 *   every outcome. What a given session's toolbox actually carries is
 *   {@see SessionDeclareTool}, the variant filtered to the endings that
 *   session may make (its constitution, §5/§8; docs/design/
 *   SESSION_ENDINGS.md), built by {@see SessionEndings::declareTool()} at
 *   resolution and frozen into the slice's snapshot. The descriptions and
 *   schemas in this file render from {@see SessionDeclarationOutcome::meaning()},
 *   so the vocabulary's meanings have exactly one source.
 *
 * These tools are injected into a session's frozen toolbox by the resolver
 * (`App\RunEngine\ToolboxResolver`: MCP tools + these), never declared by
 * the operator. Their names are reserved: a catalog tool of the same name is
 * refused at resolution rather than shadowed at dispatch.
 *
 * Which of the three declaration outcomes a session may actually make is
 * part of its constitution (§5/§8), resolved from the deployment's
 * `TASKLOOM_SESSION_ENDINGS` / `TASKLOOM_SESSION_UNDECLARED` knobs
 * (docs/design/SESSION_ENDINGS.md): the resolver swaps the Declare case for
 * the filtered {@see SessionDeclareTool} variant, and the settle path
 * refuses against exactly what that variant admits. This enum stays the
 * closed vocabulary; the constitution gates which members apply.
 */
enum SessionTool: string implements ToolDefinition
{
    /** Append one carried note — "where things are", for the model's future self. */
    case Note = 'session_note';

    /** Set the current objective — "what this is trying to achieve now". */
    case Objective = 'session_objective';

    /** End the slice: milestone / done / blocked — the declaration (§8), settled by the engine. */
    case Declare = 'session_declare';

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
            self::Declare => \sprintf(
                'End this slice with its outcome: %s. Must be ALONE in its round — no other calls beside it; nothing continues after it. The summary is the slice\'s result, shown to the operator.',
                implode(', ', array_map(
                    static fn (SessionDeclarationOutcome $outcome): string => \sprintf('%s (%s)', $outcome->value, $outcome->meaning()),
                    SessionDeclarationOutcome::cases(),
                )),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getSchema(): array
    {
        if (self::Declare === $this) {
            return [
                'type' => 'object',
                'properties' => [
                    'outcome' => [
                        'type' => 'string',
                        'enum' => array_map(
                            static fn (SessionDeclarationOutcome $outcome): string => $outcome->value,
                            SessionDeclarationOutcome::cases(),
                        ),
                        'description' => implode('; ', array_map(
                            static fn (SessionDeclarationOutcome $outcome): string => \sprintf('%s: %s', $outcome->value, $outcome->meaning()),
                            SessionDeclarationOutcome::cases(),
                        )).'.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'The slice\'s result — what you accomplished, decided, or are blocked on. Shown to the operator and kept in the ledger.',
                    ],
                ],
                'required' => ['outcome', 'summary'],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => self::Note === $this
                        ? 'The note — one thing worth carrying forward.'
                        : 'The objective — what this session is trying to achieve now.',
                ],
            ],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }
}
