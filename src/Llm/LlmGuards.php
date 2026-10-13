<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * The stream guards: when a generation that has not ended should be cut,
 * and under what names.
 *
 * Every window is in seconds and answers a different operator question:
 *
 *  - `firstTokenSeconds` — "have I given too much context, and it is too
 *    bogged down to get anywhere?" The wait for the first model-output
 *    event is dominated by prefill, so it is measured separately from the
 *    rest of the generation and names its own error class.
 *  - `silenceSeconds` — "is it wedged?" Model output was flowing, then
 *    stopped. Measured against model deltas, not wire bytes: an endpoint
 *    sending keep-alives is not producing anything.
 *  - `softDeadlineSeconds` (+ `softExtensionSeconds` ×
 *    `maxSoftExtensions`) — "is it going to babble for an hour?" A
 *    generation still producing output at the soft deadline earns a
 *    bounded extension, logged as it is granted; when the extensions are
 *    spent, the cut is made even though output is still flowing.
 *  - `maxDurationSeconds` — the hard cap, for every generation, flowing or
 *    not. This is the number deployment arithmetic (`stop_grace_period`,
 *    claim staleness) bounds itself by, which is why the extensions may
 *    not collectively reach past it.
 *
 * The loop knobs live here too — they are the same statement ("stop a
 * generation that is provably not progressing"), measured in characters
 * instead of seconds.
 *
 * Windows may be fractional (tests drive guards with sub-second windows);
 * deployment knobs are documented as whole seconds. Validation is loud —
 * the constructor throws naming the knob — following the project's rule
 * for deployment knobs: a misconfigured guard must fail where it is read,
 * not silently never fire.
 */
final readonly class LlmGuards
{
    public const float DEFAULT_FIRST_TOKEN = 90.0;

    public const float DEFAULT_SILENCE = 120.0;

    public const float DEFAULT_SOFT_DEADLINE = 300.0;

    public const float DEFAULT_SOFT_EXTENSION = 150.0;

    public const int DEFAULT_MAX_EXTENSIONS = 2;

    public const int DEFAULT_LOOP_MIN_SPAN = 160;

    public const int DEFAULT_LOOP_MIN_REPEATS = 6;

    public function __construct(
        public float $firstTokenSeconds = self::DEFAULT_FIRST_TOKEN,
        public float $silenceSeconds = self::DEFAULT_SILENCE,
        public float $softDeadlineSeconds = self::DEFAULT_SOFT_DEADLINE,
        public float $softExtensionSeconds = self::DEFAULT_SOFT_EXTENSION,
        public int $maxSoftExtensions = self::DEFAULT_MAX_EXTENSIONS,
        public float $maxDurationSeconds = self::DEFAULT_SOFT_DEADLINE + self::DEFAULT_MAX_EXTENSIONS * self::DEFAULT_SOFT_EXTENSION,
        public int $loopMinSpanChars = self::DEFAULT_LOOP_MIN_SPAN,
        public int $loopMinRepeats = self::DEFAULT_LOOP_MIN_REPEATS,
    ) {
        if ($firstTokenSeconds <= 0) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_FIRST_TOKEN_TIMEOUT must be positive, got %s.', $firstTokenSeconds));
        }
        if ($silenceSeconds <= 0) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_SILENCE_TIMEOUT must be positive, got %s.', $silenceSeconds));
        }
        if ($softDeadlineSeconds <= 0) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_SOFT_DEADLINE must be positive, got %s.', $softDeadlineSeconds));
        }
        if ($softExtensionSeconds <= 0) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_SOFT_EXTENSION must be positive, got %s.', $softExtensionSeconds));
        }
        if ($maxSoftExtensions < 0) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_MAX_EXTENSIONS must be 0 or more, got %d.', $maxSoftExtensions));
        }
        if ($maxDurationSeconds < $softDeadlineSeconds) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_MAX_DURATION (%ss) must not be below TASKLOOM_LLM_SOFT_DEADLINE (%ss) — the hard cap has to be able to fire after the soft deadline does.', $maxDurationSeconds, $softDeadlineSeconds));
        }
        // The soft deadline plus every extension it can grant must fit
        // under the hard cap, or the hard cap is what actually ends the
        // stream and the extension arithmetic is a fiction.
        $extensionCeiling = $softDeadlineSeconds + $maxSoftExtensions * $softExtensionSeconds;
        if ($extensionCeiling > $maxDurationSeconds) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_SOFT_DEADLINE + TASKLOOM_LLM_MAX_EXTENSIONS × TASKLOOM_LLM_SOFT_EXTENSION (%ss) is above TASKLOOM_LLM_MAX_DURATION (%ss) — the extensions could never all be granted. Raise the hard cap or lower the extension budget.', $extensionCeiling, $maxDurationSeconds));
        }
        if ($loopMinSpanChars < 16) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_LOOP_MIN_SPAN must be at least 16 characters, got %d — below that normal text repeats.', $loopMinSpanChars));
        }
        if ($loopMinRepeats < 3) {
            throw new \InvalidArgumentException(sprintf('TASKLOOM_LLM_LOOP_MIN_REPEATS must be at least 3, got %d.', $loopMinRepeats));
        }
    }

    /**
     * The legacy-equivalent guards: one whole-request bound `T`, no
     * extensions. Used when a client is constructed the old way, so a
     * `TASKLOOM_LLM_TIMEOUT=300` deployment that never sets a new knob
     * keeps exactly the old worst case.
     */
    public static function legacy(int $timeoutSeconds): self
    {
        $t = max(1, $timeoutSeconds);

        return new self(
            firstTokenSeconds: min(self::DEFAULT_FIRST_TOKEN, (float) $t),
            silenceSeconds: min(self::DEFAULT_SILENCE, (float) $t),
            softDeadlineSeconds: (float) $t,
            softExtensionSeconds: self::DEFAULT_SOFT_EXTENSION,
            maxSoftExtensions: 0,
            maxDurationSeconds: (float) $t,
        );
    }

    /**
     * Build from unparsed environment values ('' and null mean "unset"),
     * as wired through `services.yaml`.
     *
     * `TASKLOOM_LLM_SOFT_DEADLINE` inherits `TASKLOOM_LLM_TIMEOUT` when
     * unset — the old knob kept its meaning (one generation bound) so an
     * operator who raised it and set nothing else is not surprised by an
     * earlier cut. `TASKLOOM_LLM_MAX_DURATION` defaults to the soft
     * deadline plus every grantable extension — the smallest hard cap that
     * is consistent with the extension arithmetic, which is also why it
     * must be raised alongside a raised soft deadline.
     */
    public static function fromEnv(
        int $legacyTimeoutSeconds,
        ?string $firstTokenSeconds = null,
        ?string $silenceSeconds = null,
        ?string $softDeadlineSeconds = null,
        ?string $softExtensionSeconds = null,
        ?string $maxSoftExtensions = null,
        ?string $maxDurationSeconds = null,
        ?string $loopMinSpanChars = null,
        ?string $loopMinRepeats = null,
    ): self {
        $first = self::floatOrNull($firstTokenSeconds) ?? self::DEFAULT_FIRST_TOKEN;
        $silence = self::floatOrNull($silenceSeconds) ?? self::DEFAULT_SILENCE;
        $soft = self::floatOrNull($softDeadlineSeconds) ?? (float) max(1, $legacyTimeoutSeconds);
        $extension = self::floatOrNull($softExtensionSeconds) ?? self::DEFAULT_SOFT_EXTENSION;
        $maxExtensions = self::intOrNull($maxSoftExtensions) ?? self::DEFAULT_MAX_EXTENSIONS;
        $maxDuration = self::floatOrNull($maxDurationSeconds) ?? ($soft + $maxExtensions * $extension);

        return new self(
            firstTokenSeconds: $first,
            silenceSeconds: $silence,
            softDeadlineSeconds: $soft,
            softExtensionSeconds: $extension,
            maxSoftExtensions: $maxExtensions,
            maxDurationSeconds: $maxDuration,
            loopMinSpanChars: self::intOrNull($loopMinSpanChars) ?? self::DEFAULT_LOOP_MIN_SPAN,
            loopMinRepeats: self::intOrNull($loopMinRepeats) ?? self::DEFAULT_LOOP_MIN_REPEATS,
        );
    }

    private static function floatOrNull(?string $value): ?float
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        if (!is_numeric(trim($value))) {
            throw new \InvalidArgumentException(sprintf('Guard knob must be a number, got "%s".', $value));
        }

        return (float) trim($value);
    }

    private static function intOrNull(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        if (!is_numeric(trim($value))) {
            throw new \InvalidArgumentException(sprintf('Guard knob must be a number, got "%s".', $value));
        }

        return (int) trim($value);
    }
}
