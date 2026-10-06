<?php

declare(strict_types=1);

namespace App\Admin\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * The security posture this app renders Markdown with, in one place.
 *
 * The library's own defaults are the wrong way round for this text. It is
 * partly model output and partly a **tool result** — whatever an external MCP
 * server chose to return — so nothing in it is trusted, and CommonMark's
 * defaults assume the opposite:
 *
 *   `html_input`         defaults to `allow`, which passes raw HTML through
 *                        untouched. Set to `escape`, so a reply containing
 *                        `<script>` renders as visible text.
 *   `allow_unsafe_links` defaults to `true`. Set to `false`, so a
 *                        `javascript:` or `data:` target does not become an
 *                        `href` or an `src` at all. (It is dropped rather than
 *                        rewritten, so the link renders without one.)
 *   `max_nesting_level`  defaults to PHP_INT_MAX. Bounded, so a pathological
 *                        document cannot turn into unbounded recursion.
 *
 * The last one is a judgement call rather than a vulnerability: the input is
 * one conversation turn, not a hostile document, and CommonMark's parser is
 * fast — but a chat transcript grows for years, so a cheap ceiling is worth
 * more than the microseconds it costs.
 *
 * It also registers `ImageRenderer`, which REPLACES image support with a line
 * of text. See that class for why.
 */
final class SafeMarkdownExtension implements ExtensionInterface
{
    /**
     * Deep enough for anything a person or a model writes in a chat message.
     */
    public const int MAX_NESTING_LEVEL = 50;

    #[\Override]
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addRenderer(Image::class, new ImageRenderer(), 1)
            ->addExtension(new ExternalLinkExtension());
    }
}
