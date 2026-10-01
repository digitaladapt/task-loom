<?php

declare(strict_types=1);

namespace App\Context;

/**
 * The unit system the grounding block announces (SPEC §4.2).
 *
 * Deployment-wide config, never per-task: the model reads this once, at the
 * head of the run, and reports in those units for the whole run.
 */
enum Units: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';

    /**
     * Parse the TASKLOOM_UNITS value (null when the variable is unset).
     *
     * Fail closed on an unrecognized value, with a message naming the
     * variable: a typo must never fall back to a default, or the deployment
     * would quietly report in units the operator did not choose — the same
     * refusal TASKLOOM_TIMEZONE makes about the clock. Missing is different
     * from wrong: unset means "the historic default", so metric.
     */
    public static function fromConfig(?string $value): self
    {
        if (null === $value || '' === trim($value)) {
            return self::Metric;
        }

        return self::tryFrom(trim($value)) ?? throw new \InvalidArgumentException(\sprintf('TASKLOOM_UNITS must be "metric" or "imperial", got "%s".', $value));
    }
}
