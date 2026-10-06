<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * A small Markdown renderer for conversation text.
 *
 * ## Why this exists, and why it is hand-written
 *
 * The assistant writes Markdown — lists, emphasis, fenced code, links — and the
 * transcript rendered it with `nl2br`, so a reply arrived as asterisks and
 * backticks. On a phone, reading a bulleted answer as prose with punctuation in
 * it is materially worse than reading the list.
 *
 * SPEC §11 restricts non-Symfony dependencies to an approved, signed-off list
 * (`mcp/sdk`, `dragonmantank/cron-expression`), so pulling in `league/commonmark`
 * is a decision for Andrew, not for me. This is the interim: a deliberately
 * *small* converter that covers what conversational replies actually use, and
 * nothing else. It is written so that swapping it for CommonMark later is a
 * one-class change — the Twig filter and the CSS do not care who produced the
 * HTML.
 *
 * Unsupported on purpose, and left as literal text rather than half-rendered:
 * tables, nested lists, footnotes, definition lists, autolinks, and reference
 * links. Auto-linking bare URLs is *not* done here either — see below.
 *
 * ## Safety: it escapes first, then adds markup
 *
 * Every string is HTML-escaped **before** any markup is introduced, and nothing
 * in the pipeline can un-escape it. That ordering is the whole security
 * argument, because this text is partly model output and partly tool output:
 * neither is trusted input, and a tool result is whatever an external MCP
 * server chose to return. There is no raw-HTML pass-through at all, so a reply
 * containing `<script>` renders as visible text — it cannot become script, and
 * it cannot smuggle an event handler into an attribute.
 *
 * Link targets are restricted to `http`/`https`. Anything else (`javascript:`,
 * `data:`) stays literal text, because the URL is inserted into an attribute
 * and "escape it and hope" is not a plan.
 */
final class MarkdownRenderer
{
    /** Deepest block structure this will recurse into before giving up. */
    private const int MAX_DEPTH = 8;

    public function render(string $text, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            // Pathological input (a blockquote inside itself, forever). Show it
            // rather than looping: a renderer that hangs is worse than one that
            // shows unrendered text.
            return '<p>'.$this->escape($text).'</p>';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $count = \count($lines);
        $html = [];
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            // Fenced code block. Taken first, because inside a fence nothing
            // else applies — that is the point of a fence.
            if (1 === preg_match('/^\s*```\s*(\S*)\s*$/', $line, $match)) {
                $info = $match[1];
                $body = [];
                ++$i;
                while ($i < $count && 1 !== preg_match('/^\s*```\s*$/', $lines[$i])) {
                    $body[] = $lines[$i];
                    ++$i;
                }
                ++$i; // the closing fence, or past the end
                $html[] = $this->codeBlock($body, $info);

                continue;
            }

            // Horizontal rule. Before lists, because `- - -` is both otherwise.
            if (1 === preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $html[] = '<hr>';
                ++$i;

                continue;
            }

            if (1 === preg_match('/^(#{1,6})\s+(.*)$/', $line, $match)) {
                $level = \strlen($match[1]);
                $html[] = \sprintf('<h%d>%s</h%d>', $level, $this->inline($match[2]), $level);
                ++$i;

                continue;
            }

            if (1 === preg_match('/^\s*>\s?(.*)$/', $line, $match)) {
                $quoted = [];
                while ($i < $count && 1 === preg_match('/^\s*>\s?(.*)$/', $lines[$i], $inner)) {
                    $quoted[] = $inner[1];
                    ++$i;
                }
                $html[] = '<blockquote>'.$this->render(implode("\n", $quoted), $depth + 1).'</blockquote>';

                continue;
            }

            if (1 === preg_match('/^\s*[-*+]\s+/', $line)) {
                [$items, $i] = $this->listItems($lines, $i, '/^\s*[-*+]\s+(.*)$/');
                $html[] = '<ul>'.$items.'</ul>';

                continue;
            }

            if (1 === preg_match('/^\s*\d+[.)]\s+/', $line)) {
                [$items, $i] = $this->listItems($lines, $i, '/^\s*\d+[.)]\s+(.*)$/');
                $html[] = '<ol>'.$items.'</ol>';

                continue;
            }

            if ('' === trim($line)) {
                ++$i;

                continue;
            }

            // A paragraph: consecutive lines up to a blank line or the start of
            // another block. Single newlines inside it are line breaks, because
            // that is how a person — and this assistant — writes a chat message.
            $paragraph = [];
            while ($i < $count && '' !== trim($lines[$i]) && !$this->startsBlock($lines[$i])) {
                $paragraph[] = $this->inline($lines[$i]);
                ++$i;
            }
            $html[] = '<p>'.implode("<br>\n", $paragraph).'</p>';
        }

        return implode("\n", $html);
    }

    /**
     * @param list<string> $body
     */
    private function codeBlock(array $body, string $info): string
    {
        $class = '' === $info ? '' : ' class="language-'.$this->escape($info).'"';

        return \sprintf(
            '<pre class="md-code"><code%s>%s</code></pre>',
            $class,
            $this->escape(implode("\n", $body)),
        );
    }

    /**
     * @param list<string> $lines
     *
     * @return array{string, int} the rendered items, and where to resume
     */
    private function listItems(array $lines, int $i, string $pattern): array
    {
        $count = \count($lines);
        $items = '';

        while ($i < $count && 1 === preg_match($pattern, $lines[$i], $match)) {
            $text = [$match[1]];
            ++$i;
            // Continuation lines: an item's wrapped text, indented under it.
            while ($i < $count && '' !== trim($lines[$i])
                && 1 !== preg_match($pattern, $lines[$i])
                && 1 !== preg_match('/^\s*[-*+]\s+/', $lines[$i])
                && 1 !== preg_match('/^\s*\d+[.)]\s+/', $lines[$i])
                && 1 !== preg_match('/^\s*```/', $lines[$i])
                && 1 === preg_match('/^\s{2,}\S/', $lines[$i])
            ) {
                $text[] = trim($lines[$i]);
                ++$i;
            }
            $items .= '<li>'.$this->inline(implode(' ', $text)).'</li>';
        }

        return [$items, $i];
    }

    /** Whether a line begins a block construct, ending the current paragraph. */
    private function startsBlock(string $line): bool
    {
        foreach (['/^\s*```/', '/^(#{1,6})\s+/', '/^\s*>/', '/^\s*[-*+]\s+/', '/^\s*\d+[.)]\s+/'] as $pattern) {
            if (1 === preg_match($pattern, $line)) {
                return true;
            }
        }

        return 1 === preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line);
    }

    /**
     * Inline markup within one line.
     *
     * The escape happens first and everything after only *inserts* tags, so no
     * path through this method can emit a tag the caller did not write.
     */
    private function inline(string $text): string
    {
        $text = $this->escape($text);

        // Code spans first: `**this**` inside backticks must stay literal.
        $text = (string) preg_replace_callback(
            '/`([^`]+)`/',
            static fn (array $m): string => '<code>'.$m[1].'</code>',
            $text,
        );

        // Links, http(s) only. The target is already escaped, and the scheme is
        // pinned, so it cannot become `javascript:` or break out of the
        // attribute.
        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            static fn (array $m): string => \sprintf(
                '<a href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
                $m[2],
                $m[1],
            ),
            $text,
        );

        $text = (string) preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);

        return $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
