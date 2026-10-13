<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\SessionDeclarationOutcome;

/**
 * The constitution of a session's endings (docs/design/SESSION_TASKS.md §5,
 * §8; docs/design/SESSION_ENDINGS.md): which of the three declarations a
 * session's slices may make, what a settlement WITHOUT one means, and the
 * streak limit that bounds `continue`.
 *
 * One derivation, read everywhere: the `session_declare` tool's schema and
 * description ({@see SessionDeclareTool}), the slice prompt's note and
 * completion text, the settle path's refusal wording, and the derived-state
 * streak all render from this object — the five hand-written restatements
 * the design note listed collapse here. And like every constitutional
 * thing, a slice FREEZES it: the filtered tool rides the toolbox snapshot,
 * the rendered text rides the prompt head, and `undeclared` /
 * `maxUndeclared` ride the checkpoint ({@see \App\RunEngine\LoopState}) —
 * so a fresh worker mid-slice cannot see a different policy than the slice
 * started with (SPEC §4.1).
 *
 * Resolution: the deployment knobs, else the built-ins — every ending, and
 * `stop` (the pre-knob behavior; no deployment's sessions change behavior
 * on upgrade). `TASKLOOM_SESSION_ENDINGS` is a non-empty subset of
 * milestone/done/blocked; an empty set is refused, because a session that
 * can declare nothing could only ever settle by silence.
 *
 * The motivating shape (SESSION_ENDINGS.md §6): an endless session —
 *
 *     TASKLOOM_SESSION_ENDINGS=milestone,blocked
 *     TASKLOOM_SESSION_UNDECLARED=continue
 *
 * — which can never complete itself, and whose silence never kills it.
 */
final readonly class SessionEndings
{
    /** Consecutive undeclared settlements that stop a session when no knob says otherwise (§8). */
    public const int DEFAULT_MAX_UNDECLARED = 3;

    /** @var list<SessionDeclarationOutcome> canonical order (the enum's), never empty */
    public array $endings;

    public SessionUndeclared $undeclared;

    public int $maxUndeclared;

    /**
     * @param ?string         $endings       TASKLOOM_SESSION_ENDINGS; null/empty → every outcome
     * @param ?string         $undeclared    TASKLOOM_SESSION_UNDECLARED; null/empty → stop
     * @param int|string|null $maxUndeclared TASKLOOM_SESSION_MAX_UNDECLARED; null/empty → 3 (the env
     *                                       processor may hand this over as an int, hence the union)
     *
     * @throws \InvalidArgumentException when a value names something the vocabulary does not
     */
    public function __construct(
        ?string $endings = null,
        ?string $undeclared = null,
        int|string|null $maxUndeclared = null,
    ) {
        $this->endings = self::resolveEndings($endings);
        $this->undeclared = self::resolveUndeclared($undeclared);
        $this->maxUndeclared = self::resolveMaxUndeclared($maxUndeclared);
    }

    public function allows(SessionDeclarationOutcome $outcome): bool
    {
        return \in_array($outcome, $this->endings, true);
    }

    /** Whether the session has a completion at all — false when `done` is not admitted. */
    public function hasCompletion(): bool
    {
        return $this->allows(SessionDeclarationOutcome::Done);
    }

    /** @return list<string> the admitted outcomes, canonical order */
    public function labels(): array
    {
        return array_map(
            static fn (SessionDeclarationOutcome $outcome): string => $outcome->value,
            $this->endings,
        );
    }

    /** The admitted outcomes as one list — "milestone, blocked". */
    public function labelList(string $glue = ', '): string
    {
        return implode($glue, $this->labels());
    }

    /**
     * The `session_declare` entry a slice's toolbox carries: the vocabulary
     * filtered to this session's endings, frozen into the toolbox snapshot
     * at slice start.
     */
    public function declareTool(): SessionDeclareTool
    {
        return SessionDeclareTool::for($this->endings);
    }

    /**
     * The slice prompt's note (`PromptCompiler::compileForSlice`): what one
     * slice is, how it ends, and what this deployment's policy makes of
     * silence.
     */
    public function sliceNote(): string
    {
        $note = 'This run is ONE SLICE of a longer session: the session keeps working across slices, and what carries to the next slice is your memory store (the ## Memories block) — not this transcript.';
        $note .= ' When this slice\'s work reaches a stopping point, end it by calling session_declare with its outcome ('.self::listOf($this->endings, ' / ').') and a summary of what you did — and call it ALONE, with no other tool calls in that round.';

        if (!$this->hasCompletion()) {
            $note .= ' This session has no completion — it runs until the operator stops it.';
        }

        return $note.' '.$this->undeclaredSentence();
    }

    /**
     * The slice prompt's `## Completion` text, rendered from the frozen
     * set: bullets only for the admitted endings, an explicit line when
     * there is no completion, and the settlement policy's sentence.
     */
    public function completionText(): string
    {
        $lines = [
            'This is a slice: end it by calling session_declare with its outcome and a summary of the work.',
            '',
        ];

        foreach ($this->endings as $outcome) {
            $lines[] = $outcome->bullet();
        }

        if (!$this->hasCompletion()) {
            $lines[] = '';
            $lines[] = 'This session has no completion — it runs until the operator stops it.';
        }

        $lines[] = '';
        $lines[] = 'Call session_declare ALONE, with no other tool calls in that round.';
        $lines[] = 'If the aim has changed, write it first with session_objective.';
        $lines[] = '';
        $lines[] = match ($this->undeclared) {
            SessionUndeclared::Stop => 'A slice that ends without a declaration stops the session for a human — it is never treated as a completion.',
            SessionUndeclared::Continue => \sprintf(
                'A slice that ends without a declaration is settled as a continuation — the next slice launches — but %d consecutive undeclared settlements stop the session for a human. Declaring is how your slice is recorded; prefer it.',
                $this->maxUndeclared,
            ),
        };

        return implode("\n", $lines);
    }

    /** One sentence of policy, for the note and anywhere else that needs it alone. */
    public function undeclaredSentence(): string
    {
        return match ($this->undeclared) {
            SessionUndeclared::Stop => 'A slice that ends without a declaration stops the session for a human — it is never treated as a completion.',
            SessionUndeclared::Continue => 'A slice that ends without a declaration is settled as a continuation — the harness launches the next slice — but repeated undeclared settlements stop the session for a human.',
        };
    }

    /** @return list<SessionDeclarationOutcome> */
    private static function resolveEndings(?string $endings): array
    {
        if (null === $endings || '' === trim($endings)) {
            return SessionDeclarationOutcome::cases();
        }

        $named = [];
        foreach (explode(',', $endings) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }

            $outcome = SessionDeclarationOutcome::tryFrom($part);
            if (null === $outcome) {
                throw new \InvalidArgumentException(\sprintf('TASKLOOM_SESSION_ENDINGS names "%s", which is not a session declaration outcome. It must be a comma-separated subset of: %s.', $part, self::listOf(SessionDeclarationOutcome::cases())));
            }

            $named[$outcome->value] = true;
        }

        if ([] === $named) {
            throw new \InvalidArgumentException(\sprintf('TASKLOOM_SESSION_ENDINGS names no outcomes — a session must be able to declare at least one of: %s. Remove the variable to keep the default.', self::listOf(SessionDeclarationOutcome::cases())));
        }

        // Canonical order (the enum's), whatever order was declared: every
        // surface renders the set, so the set must read the same way
        // everywhere.
        return array_values(array_filter(
            SessionDeclarationOutcome::cases(),
            static fn (SessionDeclarationOutcome $outcome): bool => isset($named[$outcome->value]),
        ));
    }

    private static function resolveUndeclared(?string $undeclared): SessionUndeclared
    {
        if (null === $undeclared || '' === trim($undeclared)) {
            return SessionUndeclared::Stop;
        }

        return SessionUndeclared::tryFrom(trim($undeclared))
            ?? throw new \InvalidArgumentException(\sprintf('TASKLOOM_SESSION_UNDECLARED must be "stop" or "continue", got "%s".', $undeclared));
    }

    private static function resolveMaxUndeclared(int|string|null $maxUndeclared): int
    {
        if (null === $maxUndeclared || '' === trim((string) $maxUndeclared)) {
            return self::DEFAULT_MAX_UNDECLARED;
        }

        $value = trim((string) $maxUndeclared);
        if (1 !== preg_match('/^\d+$/', $value) || (int) $value < 1) {
            throw new \InvalidArgumentException(\sprintf('TASKLOOM_SESSION_MAX_UNDECLARED must be a positive whole number, got "%s".', $maxUndeclared));
        }

        return (int) $value;
    }

    /** @param list<SessionDeclarationOutcome> $outcomes */
    private static function listOf(array $outcomes, string $glue = ', '): string
    {
        return implode($glue, array_map(
            static fn (SessionDeclarationOutcome $outcome): string => $outcome->value,
            $outcomes,
        ));
    }
}
