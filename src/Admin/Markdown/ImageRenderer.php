<?php

declare(strict_types=1);

namespace App\Admin\Markdown;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

/**
 * Images, rendered as the text they are written as.
 *
 * ## Why not render them
 *
 * CommonMark honours `![alt](url)`, and so would this app — but the *URL* in a
 * chat message is written by the model, or by a tool result, and rendering it
 * would make the transcript fetch a third-party resource. That is a behaviour
 * change, not a formatting one: it leaks the reader's IP and the fact that the
 * page was opened, it makes a tracking pixel possible in a conversation, and it
 * lets a reply load something the reader never chose to load. None of that was
 * true before this renderer existed, and it is not something to acquire by
 * accident while adopting a Markdown library.
 *
 * So an image renders as its literal source, in a code span: you see what was
 * sent, you can read it, and nothing leaves the browser. A deliberate decision,
 * easily revisited — swap this renderer for the library's own (priority 0
 * against this one's 1) if images in a transcript are ever wanted, with the
 * fetch accepted knowingly.
 *
 * This is the same reasoning that keeps raw HTML escaped rather than allowed:
 * text that arrived from somewhere else does not get to reach out.
 */
final class ImageRenderer implements NodeRendererInterface
{
    /**
     * @param Image $node
     */
    #[\Override]
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Image::assertInstanceOf($node);

        $url = $node->getUrl();
        $alt = $this->altText($node);

        $label = '' === $alt ? $url : $alt.' — '.$url;

        // Escaped because `HtmlElement::setContents` requires pre-escaped
        // contents (only attributes are escaped for us), and the alt text and
        // URL are both attacker-supplied.
        return new HtmlElement(
            'code',
            ['class' => 'md-image'],
            Xml::escape($label),
        );
    }

    /**
     * The image's alt text, flattened.
     *
     * The alternative is the library's own extraction, which is a private method
     * on its renderer — reaching for it would couple this to an internal. The
     * walk is deliberately shallow: alt text is a label, not a document, so
     * anything exotic inside it is dropped rather than carefully preserved.
     */
    private function altText(Image $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }

        return trim($text);
    }
}
