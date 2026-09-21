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
 * CSP is strict: self only, no inline script/style, no framing. Turbo/
 * Stimulus or any future inline usage must go through nonces or hashes.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: 0)]
final class SecurityHeadersSubscriber
{
    private const string CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'";

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('Content-Security-Policy', self::CSP);
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
    }
}
