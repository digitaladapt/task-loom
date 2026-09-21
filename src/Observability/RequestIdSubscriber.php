<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request-ID propagation (GUIDING-LIGHT §8.5, Phase 1 observability).
 *
 * Every request gets an ID (generated, or adopted from a trusted
 * X-Request-Id header), which is:
 *   - attached to the request so the Monolog processor can stamp every log
 *     line for the request,
 *   - echoed back as X-Request-Id so a user report maps to exact log lines.
 *
 * The header is only *adopted* from the reverse proxy in front of us; since
 * the container is deployed behind TLS-terminating Caddy/Nginx on the host,
 * a client-supplied ID is only honored when the request already carries the
 * proxy's trusted marker — but for a local-first tool the simpler contract
 * applies: adopt any well-formed upstream ID (the Caddyfile can overwrite
 * it at the edge if strictness is wanted later).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 256)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: -256)]
final class RequestIdSubscriber
{
    public const string REQUEST_ATTRIBUTE = '_request_id';
    private const string HEADER = 'X-Request-Id';

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $upstream = $request->headers->get(self::HEADER);

        // Adopt a well-formed upstream ID; otherwise generate a fresh one.
        // 8-64 chars of hex keeps it unique, sortable, and log-friendly.
        $requestId = (\is_string($upstream) && 1 === preg_match('/^[0-9a-f]{8,64}$/i', $upstream))
            ? strtolower($upstream)
            : bin2hex(random_bytes(16));

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $requestId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE);

        if (\is_string($requestId) && '' !== $requestId) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }
}
