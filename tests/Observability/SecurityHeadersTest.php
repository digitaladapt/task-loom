<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testSecurityHeadersAndCspAreSetOnResponses(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();

        $csp = $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        self::assertSame('same-origin', $client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * A response that renders no template must not advertise a nonce — and in
     * particular must not advertise an EMPTY one.
     *
     * Regression guard for a live bug, reported from production as:
     *
     *   The source list for the Content Security Policy directive 'script-src'
     *   contains an invalid source: ''nonce-''. It will be ignored.
     *
     * `CspNonce::current()` returns **null** when nothing asked for a nonce, and
     * the subscriber only tested for `''` — so null fell through to the branch
     * that interpolates, and PHP's null-as-empty-string produced `'nonce-'`:
     * a source that can never match, on `/health` and every other JSON response.
     *
     * It is asserted as "every nonce source is a real token" rather than as
     * "the string 'nonce-' is absent", because the failure mode is a source
     * expression that is present but meaningless — the string check would pass
     * for `'nonce- '` or any other empty-ish spelling that is just as invalid.
     */
    public function testCspNeverCarriesAnEmptyNonceSource(): void
    {
        $client = static::createClient();

        // /health is the response that showed it: no template, so no nonce is
        // ever minted, so this is precisely the null path.
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        preg_match_all("/'nonce-([^']*)'/", $csp, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            self::assertNotSame(
                '',
                $match[1],
                \sprintf('The policy advertises an empty nonce source: %s', $csp),
            );
        }

        // And on a response with no inline script there should be no nonce
        // source at all, rather than a placeholder.
        self::assertSame([], $matches, \sprintf('A script-less response should not name a nonce: %s', $csp));
    }

    /**
     * The other half of the same rule: when a template IS rendered, the nonce
     * is real and non-empty. Without this the test above could be satisfied by
     * never emitting a nonce at all, which would block every inline script.
     */
    public function testARenderedPageCarriesARealNonce(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/'nonce-[A-Za-z0-9_-]{8,}'/", $csp, $csp);
    }
}
