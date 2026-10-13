<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\SessionDeclarationOutcome;
use App\Entity\ToolDefinition;

/**
 * The `session_declare` entry a session's frozen toolbox actually carries:
 * the declaration with its schema and description FILTERED to the endings
 * the session may make (docs/design/SESSION_ENDINGS.md §3).
 *
 * The base {@see SessionTool::Declare} case renders the unfiltered default
 * — every outcome — and stays the vocabulary's canonical definition; a
 * resolved toolbox swaps this variant in, so what the model sees on the
 * wire, what the slice prompt teaches, and what the settle path accepts are
 * one rendering, not three (the design note's five hand-written
 * restatements collapse to {@see SessionEndings} + the enum's meanings).
 *
 * The snapshot stores description + schema as they were frozen; the rebuild
 * ({@see \App\RunEngine\ToolboxSnapshot::toDefinitions()}) honours the
 * stored entry verbatim — re-deriving from the enum case would silently
 * re-widen the vocabulary on the first rehydrated turn, which is exactly
 * the turn a fresh worker runs.
 */
final readonly class SessionDeclareTool implements ToolDefinition
{
    public const string NAME = 'session_declare';

    /** @var list<SessionDeclarationOutcome> */
    private array $outcomes;

    private string $description;

    /** @var array<string, mixed> */
    private array $schema;

    /**
     * @param list<SessionDeclarationOutcome> $outcomes
     * @param array<string, mixed>            $schema
     */
    private function __construct(array $outcomes, string $description, array $schema)
    {
        $this->outcomes = $outcomes;
        $this->description = $description;
        $this->schema = $schema;
    }

    /**
     * Render the declaration entry for one set of admitted outcomes: the
     * description and the schema's `outcome` enum and description all speak
     * only the endings the session has.
     *
     * @param list<SessionDeclarationOutcome> $outcomes
     */
    public static function for(array $outcomes): self
    {
        $description = \sprintf(
            'End this slice with its outcome: %s.',
            implode(', ', array_map(
                static fn (SessionDeclarationOutcome $outcome): string => \sprintf('%s (%s)', $outcome->value, $outcome->meaning()),
                $outcomes,
            )),
        );

        if (!\in_array(SessionDeclarationOutcome::Done, $outcomes, true)) {
            $description .= ' This session has no completion — it runs until the operator stops it.';
        }

        $description .= ' Must be ALONE in its round — no other calls beside it; nothing continues after it. The summary is the slice\'s result, shown to the operator.';

        $schema = [
            'type' => 'object',
            'properties' => [
                'outcome' => [
                    'type' => 'string',
                    'enum' => array_map(
                        static fn (SessionDeclarationOutcome $outcome): string => $outcome->value,
                        $outcomes,
                    ),
                    'description' => implode('; ', array_map(
                        static fn (SessionDeclarationOutcome $outcome): string => \sprintf('%s: %s', $outcome->value, $outcome->meaning()),
                        $outcomes,
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

        return new self($outcomes, $description, $schema);
    }

    /**
     * Rebuild from a frozen snapshot entry: the stored text and schema are
     * honoured verbatim, and the outcomes are read back out of the schema's
     * enum so the settle path refuses against exactly what the wire offered.
     *
     * @param array<string, mixed> $schema
     */
    public static function fromSnapshot(string $description, array $schema): self
    {
        $outcomes = self::outcomesOf($schema);

        return new self($outcomes, '' !== $description ? $description : self::for($outcomes)->description, $schema);
    }

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getSchema(): array
    {
        return $this->schema;
    }

    /** @return list<SessionDeclarationOutcome> the admitted outcomes, canonical order */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /** @return list<string> the admitted outcomes as the wire lists them */
    public function labels(): array
    {
        return array_map(
            static fn (SessionDeclarationOutcome $outcome): string => $outcome->value,
            $this->outcomes,
        );
    }

    /** The admitted outcomes as one list — "milestone, blocked". */
    public function labelList(string $glue = ', '): string
    {
        return implode($glue, $this->labels());
    }

    public function allows(SessionDeclarationOutcome $outcome): bool
    {
        return \in_array($outcome, $this->outcomes, true);
    }

    /**
     * The refusal for a declaration this session cannot make: name the
     * alternatives, and say WHY when the outcome the model reached for is
     * the one whose absence the deployment chose — no completion, or no
     * parking (SESSION_ENDINGS.md §5: a session that cannot park itself
     * should be told how else to reach the operator).
     */
    public function describeUnavailable(SessionDeclarationOutcome $outcome): string
    {
        $detail = match (true) {
            SessionDeclarationOutcome::Done === $outcome && !$this->allows($outcome) => '"done" is not available to this session — it has no completion; it runs until the operator stops it.',
            SessionDeclarationOutcome::Blocked === $outcome && !$this->allows($outcome) => '"blocked" is not available to this session — it cannot park itself; reach the operator with your alert tool or a session note.',
            default => \sprintf('"%s" is not available to this session.', $outcome->value),
        };

        $available = $this->labels();

        return $detail.(1 === \count($available)
            ? \sprintf(' The only outcome it can declare: %s.', $available[0])
            : \sprintf(' The outcomes it can declare: %s.', implode(', ', $available)));
    }

    /**
     * The outcomes a stored schema admits; a schema without a usable enum
     * falls back to the full vocabulary — the honest default for a
     * hand-built snapshot (the settle path then refuses only what the
     * vocabulary itself excludes).
     *
     * @param array<string, mixed> $schema
     *
     * @return list<SessionDeclarationOutcome>
     */
    private static function outcomesOf(array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        $enum = \is_array($properties) ? ($properties['outcome']['enum'] ?? null) : null;

        if (!\is_array($enum)) {
            return SessionDeclarationOutcome::cases();
        }

        $outcomes = [];
        foreach ($enum as $value) {
            $outcome = \is_string($value) ? SessionDeclarationOutcome::tryFrom($value) : null;
            if (null !== $outcome && !\in_array($outcome, $outcomes, true)) {
                $outcomes[] = $outcome;
            }
        }

        return [] === $outcomes ? SessionDeclarationOutcome::cases() : $outcomes;
    }
}
