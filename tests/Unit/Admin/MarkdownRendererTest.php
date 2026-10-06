<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    /** Nothing outside this set is ever emitted. */
    private const array ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'em', 'code', 'pre', 'ul', 'ol', 'li',
        'blockquote', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /** Nor any attribute outside this set — `onclick`, `style` and `src` included. */
    private const array ALLOWED_ATTRIBUTES = ['class', 'href', 'rel', 'target'];

    private MarkdownRenderer $renderer; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    public function testParagraphsAndLineBreaks(): void
    {
        self::assertSame(
            "<p>one<br>\ntwo</p>\n<p>three</p>",
            $this->renderer->render("one\ntwo\n\nthree"),
        );
    }

    public function testUnorderedAndOrderedLists(): void
    {
        self::assertSame(
            '<ul><li>a</li><li>b</li></ul>',
            $this->renderer->render("- a\n- b"),
        );
        self::assertSame(
            '<ol><li>first</li><li>second</li></ol>',
            $this->renderer->render("1. first\n2. second"),
        );
    }

    public function testFencedCodeKeepsItsContentLiteral(): void
    {
        $html = $this->renderer->render("```php\n\$a = 1; // <b>not bold</b>\n```");

        self::assertStringContainsString('<pre class="md-code">', $html);
        self::assertStringContainsString('<code class="language-php">', $html);
        // Markup inside a fence is text, and so is anything that looks like it.
        self::assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt;', $html);
        // And the fence's own syntax must not survive as markdown.
        self::assertStringNotContainsString('```', $html);
    }

    public function testHeadingsQuotesAndRules(): void
    {
        self::assertStringContainsString('<h2>Title</h2>', $this->renderer->render('## Title'));
        self::assertStringContainsString('<blockquote>', $this->renderer->render('> quoted'));
        self::assertStringContainsString('<hr>', $this->renderer->render('---'));
    }

    public function testInlineEmphasisAndCode(): void
    {
        $html = $this->renderer->render('a **bold** and *slim* and `code_snake_case`');

        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<em>slim</em>', $html);
        // The underscores inside a code span must survive: a code span is
        // processed before emphasis precisely so identifiers stay intact.
        self::assertStringContainsString('<code>code_snake_case</code>', $html);
    }

    public function testAListItemWithAContinuationLine(): void
    {
        $html = $this->renderer->render("- first line\n  and its continuation\n- second");

        self::assertStringContainsString('<li>first line and its continuation</li>', $html);
        self::assertStringContainsString('<li>second</li>', $html);
    }

    public function testAnHttpLinkIsRenderedWithSafeRel(): void
    {
        $html = $this->renderer->render('[docs](https://example.com/a_b)');

        self::assertStringContainsString(
            '<a href="https://example.com/a_b" rel="noopener noreferrer" target="_blank">docs</a>',
            $html,
        );
    }

    /**
     * And a link target can only ever be http(s): the URL is interpolated into
     * an attribute, so the scheme is pinned rather than escaped and hoped for.
     */
    public function testNonHttpLinkTargetsStayLiteralText(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'vbscript:x'] as $target) {
            $html = $this->renderer->render(\sprintf('[click](%s)', $target));

            self::assertStringNotContainsString('<a ', $html, $target);
            self::assertStringNotContainsString('href=', $html, $target);
        }
    }

    /**
     * The security argument, stated as a test.
     *
     * This text is partly model output and partly a tool result — whatever an
     * external MCP server chose to return — so neither may become executable.
     *
     * The invariant is **not** "the output never contains the word onerror":
     * escaped text legitimately contains that word, as visible characters,
     * which is exactly what should happen to it. The invariant is that the only
     * tags in the output are the ones this renderer writes, and the only
     * attributes are the ones it writes. Anything else — a script tag, an event
     * handler, a smuggled attribute — would have to be one of those names to do
     * any damage at all.
     */
    #[DataProvider('nastyInput')]
    public function testOnlyTheRenderersOwnMarkupCanAppear(string $input): void
    {
        $html = $this->renderer->render($input);

        preg_match_all('#</?\s*([a-zA-Z][a-zA-Z0-9]*)#', $html, $tags);
        foreach (array_unique($tags[1]) as $tag) {
            self::assertContains(
                strtolower($tag),
                self::ALLOWED_TAGS,
                \sprintf('Input %s produced a <%s> tag', var_export($input, true), $tag),
            );
        }

        preg_match_all('#<[a-zA-Z][^>]*?\s([a-zA-Z-]+)\s*=#', $html, $attributes);
        foreach (array_unique($attributes[1]) as $attribute) {
            self::assertContains(
                strtolower($attribute),
                self::ALLOWED_ATTRIBUTES,
                \sprintf('Input %s produced a %s= attribute', var_export($input, true), $attribute),
            );
        }
    }

    /**
     * Inputs that would be dangerous if any of them survived as markup.
     *
     * @return iterable<string, array{string}>
     */
    public static function nastyInput(): iterable
    {
        yield 'a script tag' => ['<script>alert(1)</script>'];
        yield 'an img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'a javascript: link' => ['[click](javascript:alert(1))'];
        yield 'a data: link' => ['[click](data:text/html;base64,PHNjcmlwdD4=)'];
        yield 'an event handler in an anchor' => ['<a href="#" onclick="x()">y</a>'];
        yield 'a tag broken by a newline' => ["<img\nsrc=x onerror=alert(1)>"];
        yield 'an svg onload' => ['<svg onload=alert(1)>'];
        yield 'a style escaping' => ['<style>body{background:url(javascript:x)}</style>'];
        yield 'an attribute quote escape' => ['<a href=" x=" onmouseover="alert(1)">z</a>'];
        yield 'an iframe' => ['<iframe src="javascript:alert(1)"></iframe>'];
        yield 'markdown emphasis around a tag' => ['**<script>alert(1)</script>**'];
        yield 'a tag inside a code span' => ['`<script>alert(1)</script>`'];
        yield 'a tag in a fence' => ["```\n<script>alert(1)</script>\n```"];
        yield 'a heading full of markup' => ['## <script>alert(1)</script>'];
        yield 'a list item full of markup' => ['- <img src=x onerror=alert(1)>'];
        yield 'a link whose text is markup' => ['[<script>x</script>](https://example.com)'];
        yield 'a blockquote full of markup' => ['> <script>alert(1)</script>'];
    }

    public function testEmptyInputIsEmpty(): void
    {
        self::assertSame('', $this->renderer->render(''));
    }

    /**
     * A fence that never closes must consume the rest of the text rather than
     * dropping it, and must not run past the end of the array.
     */
    public function testUnclosedFenceKeepsItsContent(): void
    {
        $html = $this->renderer->render("```\nstill code\nand more");

        self::assertStringContainsString('still code', $html);
        self::assertStringContainsString('and more', $html);
    }

    /**
     * Blockquotes recurse, so nesting must terminate rather than run forever.
     */
    public function testDeepNestingTerminates(): void
    {
        $html = $this->renderer->render(str_repeat('> ', 20).'deep');

        self::assertStringContainsString('deep', $html);
    }

    /** Windows and bare-CR line endings are normalised, not left to corrupt a block. */
    public function testCrlfIsNormalised(): void
    {
        self::assertSame(
            '<ul><li>a</li><li>b</li></ul>',
            $this->renderer->render("- a\r\n- b"),
        );
    }
}
