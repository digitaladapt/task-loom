<?php

declare(strict_types=1);

namespace App\Twig;

use App\Observability\CspNonce;
use App\Scheduler\SchedulePreset;
use Twig\Attribute\AsTwigFunction;

/**
 * The small template helpers the admin UI needs that are not part of Twig or
 * of an installed bundle — deliberately few.
 */
final readonly class AdminExtension
{
    public function __construct(
        private CspNonce $nonce,
    ) {
    }

    /**
     * The `csp_nonce()` template helper: the nonce every inline `<script>` in
     * this app must carry to satisfy its own Content-Security-Policy (see
     * App\Observability\CspNonce for why).
     *
     * Usage — the AssetMapper importmap and its entrypoint import are both
     * inline scripts, so both need it:
     *
     *     {{ importmap('app', {nonce: csp_nonce()}) }}
     *
     * Exposed as a function rather than a pre-set variable so minting stays
     * lazy: a template that renders no script never causes a nonce to exist,
     * and the header subscriber can therefore tell "this response has inline
     * scripts" from "this response does not" just by looking at the request.
     * A global variable would mint one on every render.
     */
    #[AsTwigFunction('csp_nonce', isSafe: ['html_attr'])]
    public function cspNonce(): string
    {
        return $this->nonce->value();
    }

    /**
     * Weekday names in cron day-of-week order (0 = Sunday), for the schedule
     * picker — derived from the same constant SchedulePreset composes
     * expressions from, so the option value a browser submits and the number
     * cron receives are the same index by construction.
     *
     * @return list<string>
     */
    #[AsTwigFunction('weekday_names')]
    public function weekdayNames(): array
    {
        return SchedulePreset::WEEKDAY_NAMES;
    }
}
