<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Admin\JsonPresenter;
use App\Admin\MarkdownRenderer;
use App\Observability\CspNonce;
use App\Twig\AdminExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The run page's payload view is bounded.
 *
 * Tool results are stored whole and capped at render (docs/design/THE_STREAM.md
 * §2.3), so an event payload is now as large as whatever the tool returned.
 * `pretty_json` runs once per event on the run page, inside a `<details>` — the
 * browser lays it out whether or not anyone opens it, and the server pays
 * `json_encode` plus escaping on the whole string first.
 *
 * The bound has to leave ordinary payloads alone, though: the payload drawer is
 * how a person debugs a run, and quietly shortening a small one would be worse
 * than the page weight it saves.
 */
final class AdminExtensionPayloadViewTest extends TestCase
{
    public function testAnOversizedPayloadIsNotRenderedWhole(): void
    {
        $extension = $this->extension();

        $rendered = $extension->prettyJson(['tool' => 'read_file', 'content' => str_repeat('x', 200_000)]);

        self::assertLessThan(40_000, \strlen($rendered), 'a 200 KB payload must not reach the template whole');
        self::assertStringContainsString('full payload in the ledger', $rendered, 'the view says where the rest is');
    }

    public function testAnOrdinaryPayloadIsRenderedIntact(): void
    {
        $extension = $this->extension();

        $rendered = $extension->prettyJson(['tool' => 'echo', 'content' => 'hello', 'isError' => false]);

        // The whole point of the drawer: what a person reads is the real payload.
        self::assertStringContainsString('&quot;tool&quot;: &quot;echo&quot;', $rendered);
        self::assertStringNotContainsString('not rendered', $rendered);
    }

    public function testTheBoundIsCharacterSafe(): void
    {
        $extension = $this->extension();

        // Multi-byte content either side of the cut: a naive byte cut would
        // ship half a character, which is the failure Utf8::cut exists for.
        $rendered = $extension->prettyJson(['content' => str_repeat('é', 60_000)]);

        self::assertTrue(mb_check_encoding($rendered, 'UTF-8'), 'the rendered payload must stay valid UTF-8');
    }

    private function extension(): AdminExtension
    {
        // The nonce is irrelevant here — nothing under test reads it — but the
        // collaborator still has to exist.
        $nonce = new CspNonce(new RequestStack());

        return new AdminExtension($nonce, new MarkdownRenderer(), new JsonPresenter());
    }
}
