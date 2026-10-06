<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The renderer is `league/commonmark`; what these tests cover is the *policy*
 * configured on top of it and the two behaviours this app overrides.
 *
 * The parsing itself is the library's business and is tested by the library —
 * asserting its CommonMark conformance here would be duplicated work that drifts.
 */
final class MarkdownRendererTest extends TestCase
{
    /**
     * The only tags this configuration can emit.
     *
     * Derived by exercising every feature (see the feature-sweep test), not by
     * guessing — an allowlist copied from a docblock is a list that stops being
     * true.
     */
    private const array ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'em', 'del', 'code', 'pre', 'ul', 'ol', 'li',
        'blockquote', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        // GitHub-Flavored Markdown: tables and task lists.
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'input',
    ];

    /**
     * Nor any attribute outside this set.
     *
     * `onclick`, `style`, `src` and `onerror` are absent by construction: there
     * is no raw-HTML pass-through, images do not render, and the library escapes
     * every attribute value it writes.
     */
    private const array ALLOWED_ATTRIBUTES = [
        'class', 'href', 'rel', 'target',       // links, code fences, our image stub
        'checked', 'disabled', 'type',          // GFM task lists
        'align',                                // GFM table alignment
    ];

    private MarkdownRenderer $renderer; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    // ---- the two deliberate overrides -------------------------------------

    /**
     * A single newline is a line break, not a space.
     *
     * CommonMark's document semantics would join the lines; this assistant writes
     * chat messages where a newline means a newline, and silently reflowing every
     * reply would be a worse bug than the one this renderer fixes.
     */
    public function testASoftBreakStaysALineBreak(): void
    {
        self::assertSame("<p>one<br>\ntwo</p>\n", $this->renderer->render("one\ntwo"));
    }

    /**
     * Images render as their source, never as a fetch.
     *
     * A URL in a transcript is written by the model or a tool result, and
     * honouring `![]()` would make the page request a third-party resource:
     * leaking the reader's IP and the fact that they opened the page, and making
     * a tracking pixel possible in a conversation. See ImageRenderer.
     */
    public function testImagesRenderAsTextAndMakeNoRequest(): void
    {
        $html = $this->renderer->render('![alt text](https://tracker.example/pixel.png)');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('src=', $html);
        // The reader can still see exactly what was sent.
        self::assertStringContainsString('alt text', $html);
        self::assertStringContainsString('https://tracker.example/pixel.png', $html);
    }

    public function testAnImageWithNoAltStillShowsItsUrl(): void
    {
        $html = $this->renderer->render('![](https://tracker.example/x.png)');

        self::assertStringContainsString('https://tracker.example/x.png', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    // ---- the safety posture ----------------------------------------------

    /**
     * Raw HTML is escaped, never passed through.
     *
     * This is the setting that matters most, because the library's own default
     * is `allow`: with it, a tool result could put a `<script>` in the
     * transcript.
     */
    #[DataProvider('nastyInput')]
    public function testOnlyTheConfigurationsOwnMarkupCanAppear(string $input): void
    {
        $this->assertMarkupWithinAllowlist($input, $this->renderer->render($input));
    }

    /**
     * The invariant, in one place, for both the hostile inputs and the feature
     * sweep.
     *
     * Violations are **collected and asserted once** rather than asserted inside
     * the loops, so an input that produces no markup at all still performs an
     * assertion — otherwise the case passes without checking anything, which
     * PHPUnit rightly reports as risky.
     *
     * Note what is deliberately NOT asserted: that the output does not contain
     * the substring `onerror=`. Escaped text legitimately contains it — the
     * characters are shown to the reader, which is the correct outcome — so a
     * substring check fails on correct output and would push toward weakening
     * the escaping to make a test green. This is the second time that assertion
     * looked reasonable; it is not in the file for that reason.
     */
    private function assertMarkupWithinAllowlist(string $input, string $html): void
    {
        $violations = [];

        preg_match_all('#</?\s*([a-zA-Z][a-zA-Z0-9]*)#', $html, $tags);
        foreach (array_unique($tags[1]) as $tag) {
            if (!\in_array(strtolower($tag), self::ALLOWED_TAGS, true)) {
                $violations[] = 'tag <'.$tag.'>';
            }
        }

        preg_match_all('#<[a-zA-Z][^>]*?\s([a-zA-Z-]+)\s*=#', $html, $attributes);
        foreach (array_unique($attributes[1]) as $attribute) {
            if (!\in_array(strtolower($attribute), self::ALLOWED_ATTRIBUTES, true)) {
                $violations[] = 'attribute '.$attribute.'=';
            }
        }

        self::assertSame(
            [],
            $violations,
            \sprintf('Input %s produced markup outside the allowlist', var_export($input, true)),
        );
    }

    /**
     * Inputs that would be dangerous if any of them survived as markup:
     * an external MCP server chose the tool result, and the model chose the
     * reply, so neither is trusted.
     *
     * @return iterable<string, array{string}>
     */
    public static function nastyInput(): iterable
    {
        yield 'a script tag' => ['<script>alert(1)</script>'];
        yield 'an img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'a javascript: link' => ['[click](javascript:alert(1))'];
        yield 'a data: link' => ['[click](data:text/html;base64,PHNjcmlwdD4=)'];
        yield 'a vbscript: link' => ['[click](vbscript:msgbox(1))'];
        yield 'an event handler in an anchor' => ['<a href="#" onclick="x()">y</a>'];
        yield 'a tag broken by a newline' => ["<img\nsrc=x onerror=alert(1)>"];
        yield 'an svg onload' => ['<svg onload=alert(1)>'];
        yield 'a style escaping' => ['<style>body{background:url(javascript:x)}</style>'];
        yield 'an attribute quote escape' => ['<a href=" x=" onmouseover="alert(1)">z</a>'];
        yield 'an iframe' => ['<iframe src="javascript:alert(1)"></iframe>'];
        yield 'markdown emphasis around a tag' => ['**<script>alert(1)</script>**'];
        yield 'a tag inside a code span' => ['`<script>alert(1)</script>`'];
        yield 'a tag in a fence' => ["```\n<script>alert(1)</script>\n```"];
        yield 'a fence info string trying to break out' => ["```php\" onload=\"alert(1)\nx\n```"];
        yield 'a heading full of markup' => ['## <script>alert(1)</script>'];
        yield 'a list item full of markup' => ['- <img src=x onerror=alert(1)>'];
        yield 'a link whose text is markup' => ['[<script>x</script>](https://example.com)'];
        yield 'a blockquote full of markup' => ['> <script>alert(1)</script>'];
        yield 'an image from a javascript: url' => ['![x](javascript:alert(1))'];
        yield 'a table cell full of markup' => ["| a |\n|---|\n| <script>x</script> |"];
        yield 'raw html inside a table' => ["| a |\n|---|\n| <td onmouseover=alert(1)> |"];
    }

    /**
     * An unsafe link target is dropped, not rewritten.
     *
     * The library's `allow_unsafe_links` is off, so the `<a>` renders with no
     * `href` at all — inert. Its link *text* survives, so the reader sees what
     * was sent.
     */
    public function testUnsafeLinkTargetsLoseTheirHref(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'vbscript:x'] as $target) {
            $html = $this->renderer->render(\sprintf('[click](%s)', $target));

            self::assertStringNotContainsString('href=', $html, $target);
            self::assertStringContainsString('click', $html, $target);
        }
    }

    public function testAnHttpLinkOpensSafelyInANewTab(): void
    {
        $html = $this->renderer->render('[docs](https://example.com/a_b)');

        self::assertStringContainsString('href="https://example.com/a_b"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        // The underscores in the URL must survive; that was a real hazard in the
        // hand-written version, where emphasis could eat them.
        self::assertStringNotContainsString('<em>', $html);
    }

    // ---- the features that are the point of using the library -------------

    /**
     * GitHub-Flavored Markdown works, which is the dialect a model emits: all of
     * these rendered as literal punctuation or as a paragraph of pipes before.
     */
    public function testTheGfmFeaturesAModelActuallyUses(): void
    {
        self::assertStringContainsString(
            '<del>struck</del>',
            $this->renderer->render('~~struck~~'),
        );

        $table = $this->renderer->render("| Tool | Calls |\n|---|---|\n| echo | 1 |");
        self::assertStringContainsString('<table>', $table);
        self::assertStringContainsString('<th>Tool</th>', $table);
        self::assertStringContainsString('<td>echo</td>', $table);

        $task = $this->renderer->render("- [x] done\n- [ ] todo");
        self::assertStringContainsString('checked', $task);
        self::assertStringContainsString('disabled', $task);

        $auto = $this->renderer->render('see https://example.com/x for it');
        self::assertStringContainsString('href="https://example.com/x"', $auto);
    }

    public function testNestedListsAndLooseSpacingAreTheLibrariesProblem(): void
    {
        $html = $this->renderer->render("- one\n  - one.a\n  - one.b\n- two");

        // The nesting the hand-written version did not support at all.
        self::assertSame(2, substr_count($html, '<ul>'));
        self::assertStringContainsString('<li>one.a</li>', $html);
    }

    public function testHeadingsQuotesRulesAndFences(): void
    {
        self::assertStringContainsString('<h2>Title</h2>', $this->renderer->render('## Title'));
        self::assertStringContainsString('<blockquote>', $this->renderer->render('> quoted'));
        self::assertStringContainsString('<hr', $this->renderer->render('---'));

        $code = $this->renderer->render("```php\n\$a = 1;\n```");
        self::assertStringContainsString('<pre>', $code);
        self::assertStringContainsString('<code class="language-php">', $code);
        self::assertStringContainsString('$a = 1;', $code);
    }

    public function testEmptyInputIsEmpty(): void
    {
        self::assertSame('', $this->renderer->render(''));
    }

    /**
     * A fence that never closes must consume the rest of the text rather than
     * dropping it.
     */
    public function testUnclosedFenceKeepsItsContent(): void
    {
        $html = $this->renderer->render("```\nstill code\nand more");

        self::assertStringContainsString('still code', $html);
        self::assertStringContainsString('and more', $html);
    }

    /**
     * Nesting is bounded, so a pathological document terminates.
     *
     * The library's default is PHP_INT_MAX; this configuration caps it.
     */
    public function testDeepNestingTerminates(): void
    {
        $html = $this->renderer->render(str_repeat('> ', 200).'deep');

        // What matters is that it returns at all, and that it returns the text.
        self::assertStringContainsString('deep', $html);
    }

    /**
     * The allowlist above is derived, not guessed: this sweep renders one of
     * every construct and fails if the configuration starts emitting a tag or
     * attribute the list does not know about.
     */
    public function testTheFeatureSweepMatchesTheAllowlist(): void
    {
        $sweep = <<<'MD'
        # h1
        ## h2
        ### h3
        #### h4
        ##### h5
        ###### h6

        para **strong** *em* `code` ~~del~~ <https://auto.example/a_b>

        > quote

        - ul
          - nested

        1. ol

        - [x] task

        | L | C |
        |:--|:-:|
        | a | b |

        ---

        ```
        fenced
        ```

        ![alt](https://img.example/x.png)
        MD;

        $html = $this->renderer->render($sweep);

        $this->assertMarkupWithinAllowlist('the feature sweep', $html);

        // And it really did exercise the features, so a sweep that renders
        // nothing cannot pass.
        foreach (['<h1>', '<h6>', '<em>', '<strong>', '<del>', '<table>', '<input', '<ul>', '<ol>', '<blockquote>', '<hr', '<pre>', 'md-image'] as $expected) {
            self::assertStringContainsString($expected, $html, \sprintf('the sweep should have produced %s', $expected));
        }
    }
}
