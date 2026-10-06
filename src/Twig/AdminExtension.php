<?php

declare(strict_types=1);

namespace App\Twig;

use App\Admin\JsonPresenter;
use App\Admin\MarkdownRenderer;
use App\Observability\CspNonce;
use App\Scheduler\SchedulePreset;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * The small template helpers the admin UI needs that are not part of Twig or
 * of an installed bundle — deliberately few.
 */
final readonly class AdminExtension
{
    public function __construct(
        private CspNonce $nonce,
        private MarkdownRenderer $markdown,
        private JsonPresenter $json,
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
     * `markdown` — render conversational text as the markup it already is.
     *
     * Marked safe for HTML on purpose: the filter's contract is that it escapes
     * every input before adding any markup of its own, and adds no raw-HTML
     * pass-through at all (see MarkdownRenderer's class docblock for the
     * argument). A caller that pipes something *else* through this needs to
     * know it is safe, so `|markdown` should only ever be applied to text.
     *
     * Uses Twig's `html` strategy rather than `isSafe: true`, which is the same
     * guarantee stated for the new syntax (`isSafe` is deprecated in Twig 4).
     */
    #[AsTwigFilter('markdown', isSafe: ['html'])]
    public function markdown(string $text): string
    {
        return $this->markdown->render($text);
    }

    /**
     * `pretty_json` — a payload a human can read, with JSON-in-a-string decoded
     * so it is shown once rather than twice-escaped (see JsonPresenter).
     *
     * Display only: it never touches what the model is sent.
     */
    #[AsTwigFilter('pretty_json', isSafe: ['html'])]
    public function prettyJson(mixed $value): string
    {
        return htmlspecialchars($this->json->pretty($value), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
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
