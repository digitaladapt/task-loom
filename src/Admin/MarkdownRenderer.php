<?php

declare(strict_types=1);

namespace App\Admin;

use App\Admin\Markdown\SafeMarkdownExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\HtmlFilter;

/**
 * Conversation text as HTML, for the chat transcript.
 *
 * ## Why this exists
 *
 * The assistant writes Markdown — lists, emphasis, fenced code, links — and the
 * transcript rendered it with `nl2br`, so a reply arrived as asterisks and
 * backticks. On a phone, reading a bulleted answer as prose with punctuation in
 * it is materially worse than reading the list.
 *
 * This started as a hand-written converter, because SPEC §11 restricts
 * non-Symfony dependencies to an approved list and `league/commonmark` was not
 * on it. Andrew approved it, which is the right call: it is actively maintained,
 * BSD-3, and it implements the actual spec rather than my approximation of it.
 * Tables, nested lists, reference links, task lists, strikethrough, bare-URL
 * autolinking and tight/loose list spacing are all correct now, and none of
 * them were before.
 *
 * GitHub-Flavored Markdown is included because that is the dialect a model
 * actually emits: a reply containing `| a | b |` is trying to be a table, and
 * rendering it as a paragraph of pipes is the failure the previous
 * implementation had. It is a superset of CommonMark, so nothing is lost by it.
 *
 * What is left here is the **policy**, not the parsing: `league/commonmark`'s
 * defaults are the wrong way round for text that arrived from a model and an
 * external MCP server, so the environment is configured, and two behaviours are
 * overridden. See SafeMarkdownExtension and ImageRenderer for the arguments.
 *
 * ## The one visible behaviour kept from the previous implementation
 *
 * `renderer/soft_break` is `<br>`. CommonMark's default is a newline, which is
 * correct for a document and wrong for a chat message: this assistant writes
 * replies where a single newline is a line break, and rendering those as a space
 * would silently reflow every reply. Hard breaks (`two trailing spaces`) and
 * `<br />` keep working as CommonMark defines them.
 *
 * ## Safety
 *
 * Raw HTML is escaped, always — there is no pass-through mode, so a reply
 * containing `<script>` renders as visible text and cannot become script. Link
 * targets are restricted to safe URLs by the library (`allow_unsafe_links` is
 * off), and images do not render at all (so a reply cannot make the page fetch
 * anything). The test asserts the invariant that actually matters — the only
 * tags and attributes in the output are ones this configuration can produce —
 * rather than looking for scary substrings, because escaped text legitimately
 * contains the word `onerror`.
 */
final class MarkdownRenderer
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            // Escape raw HTML rather than allowing it. This is the single most
            // important setting here: the library defaults to `allow`.
            'html_input' => HtmlFilter::ESCAPE,

            // Drop `javascript:`, `data:` and friends outright instead of
            // emitting them as an `href`.
            'allow_unsafe_links' => false,

            // Bounded, so a pathological document cannot become unbounded work.
            'max_nesting_level' => SafeMarkdownExtension::MAX_NESTING_LEVEL,

            'renderer' => [
                // A newline in a chat message is a line break. See the class
                // docblock — this is the one deliberate deviation from
                // CommonMark's document semantics.
                'soft_break' => "<br>\n",
            ],

            'external_link' => [
                // Every link is external, because the app links nowhere of its
                // own from a transcript. `internal_hosts: []` makes the library
                // treat all of them as external, which is what adds the rel.
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'noopener' => 'all',
                'noreferrer' => 'all',
            ],
        ]);

        $environment
            ->addExtension(new CommonMarkCoreExtension())
            ->addExtension(new GithubFlavoredMarkdownExtension())
            ->addExtension(new SafeMarkdownExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $text): string
    {
        return $this->converter->convert($text)->getContent();
    }
}
