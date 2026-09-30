<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\AdminUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class AssetMapperTest extends WebTestCase
{
    public function testBaseTemplateRendersImportmap(): void
    {
        $client = static::createClient();

        // There is no HTML route yet in v1 (health/ready are JSON), so render
        // the real base template through Twig to prove the importmap wiring.
        $twig = static::getContainer()->get(Environment::class);
        \assert($twig instanceof Environment);

        $html = $twig->render('base.html.twig', ['title' => 'test', 'body' => '']);

        self::assertStringContainsString('importmap', $html);
        self::assertStringContainsString('/assets/app-', $html, 'AssetMapper should emit a hashed entrypoint script.');
        // The stylesheet is linked from base.html.twig (not imported from
        // app.js), so the mapper emits a hashed <link> — never the legacy
        // unhashed asset() link. Assert on the tag, not the bare string.
        self::assertStringNotContainsString('href="/styles/app.css"', $html, 'Legacy unhashed asset() CSS link should be gone.');
        self::assertStringContainsString('href="/assets/styles/app-', $html, 'AssetMapper should emit a hashed stylesheet link.');
    }

    /**
     * The importmap must not carry a `data:` URL for the stylesheet.
     *
     * Regression guard for a live bug: app.js used to `import` the CSS, which
     * AssetMapper publishes as an importmap entry spelled
     * `data:application/javascript,…`. The browser then loads that entry as a
     * *script*, which the app's own `script-src 'self'` policy blocks — so the
     * entrypoint module failed to evaluate and no JavaScript ran at all.
     */
    public function testImportMapDoesNotExposeCssAsADataUrlScript(): void
    {
        $client = static::createClient();
        $client->loginUser(new AdminUser());

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertDoesNotMatchRegularExpression(
            '/"[^"]*\.css"\s*:\s*"data:/',
            $html,
            'A CSS module published as a data: script would be blocked by our own CSP.',
        );
        self::assertStringNotContainsString('data:application/javascript', $html);
    }

    /**
     * Every inline <script> must carry the request's CSP nonce, and the policy
     * must name it — the fix for the other half of that bug: the importmap and
     * entrypoint tags are inline by design, and a strict `script-src 'self'`
     * without a nonce blocked them.
     */
    public function testInlineScriptsCarryTheNonceNamedByThePolicy(): void
    {
        $client = static::createClient();
        $client->loginUser(new AdminUser());

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $matches);
        $nonce = $matches[1];

        $html = (string) $client->getResponse()->getContent();
        preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', $html, $scripts);
        self::assertNotEmpty($scripts[0], 'the importmap and entrypoint tags are inline scripts');

        foreach ($scripts[0] as $tag) {
            self::assertStringContainsString(\sprintf('nonce="%s"', $nonce), $tag, \sprintf('Inline script without the policy nonce: %s', $tag));
        }
    }
}
