<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security headers from the app itself (GUIDING-LIGHT §8.9).
 *
 * The Caddyfile sets the same headers at the edge; setting them here too
 * means they survive a proxy swap or direct exposure. The listener uses
 * set() (replace), so app-level values win over proxy-injected ones.
 *
 * CSP stays strict: self only, no framing. The app's own inline scripts (the
 * AssetMapper importmap and entrypoint tag) are the one exception, and they
 * are admitted by **nonce** rather than by 'unsafe-inline' — see CspNonce
 * for the bug that made this necessary. Every response that carries an inline
 * script carries the matching nonce; responses with no scripts are unaffected.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: 0)]
final class SecurityHeadersSubscriber
{
    private const string CSP_TEMPLATE = "default-src 'self'; script-src 'self'{NONCE}; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'";

    public function __construct(
        private readonly CspNonce $nonce,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('Content-Security-Policy', $this->policy());
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
    }

    /**
     * The policy for the current request. The nonce is only minted when it is
     * already present on the request — i.e. the renderer asked for one — so
     * responses that emit no inline scripts keep the plain `'self'` policy and
     * do not advertise a nonce nobody used.
     *
     * `current()` returns **null** when nothing asked for a nonce, and that
     * null is the case this method has to get right. Testing only for `''`
     * missed it: PHP renders null as the empty string, so the branch that was
     * supposed to emit nothing emitted `'nonce-'` instead — a source expression
     * that can never match, on every response that renders no template
     * (`/health`, the JSON endpoints, anything a healthcheck polls). Browsers
     * report it as:
     *
     *   The source list for the Content Security Policy directive 'script-src'
     *   contains an invalid source: ''nonce-''. It will be ignored.
     *
     * The policy stays correct by accident — an unmatched source admits
     * nothing — but it is noise in every console, it is not valid CSP, and a
     * strict proxy in front of the app may reject it. Both spellings of
     * "no nonce" must reach the same branch.
     */
    private function policy(): string
    {
        $nonce = $this->nonce->current();

        return str_replace(
            '{NONCE}',
            null === $nonce || '' === $nonce ? '' : " 'nonce-{$nonce}'",
            self::CSP_TEMPLATE,
        );
    }
}
