<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A per-request CSP nonce: the one value that lets the app's own inline
 * `<script>` blocks run under a strict `script-src` policy (GUIDING-LIGHT
 * §8.9, SPEC §8's "no unsafe-inline" posture).
 *
 * Why this exists — a live bug, not a preference. The Content-Security-Policy
 * this app serves is `script-src 'self'` with no `'unsafe-inline'`, but the
 * AssetMapper importmap and the entrypoint tag are **inline** scripts by
 * design (an importmap must be inline; the module import needs the map). No
 * CSP nonce or hash was supplied, so the browser blocked both — which meant
 * *no JavaScript ran anywhere in the admin UI*: the service worker never
 * registered, and any scripted surface (the step-graph builder) would have
 * been dead on arrival. The policy was right; the delivery was missing its
 * nonce.
 *
 * The nonce is minted once per request, lazily, and cached on the request
 * object. Requests that never render a template never mint one, so a JSON or
 * MCP request pays nothing.
 *
 * Kept deliberately stateless and framework-agnostic: the security-header
 * subscriber reads it to build the policy, and the Twig helper reads it to
 * stamp the scripts. Neither owns it, so the two cannot disagree — if a
 * nonce is minted, it appears in the header and on the tags in the same
 * response.
 */
final class CspNonce
{
    /** Request attribute the nonce is cached under. */
    public const string REQUEST_ATTRIBUTE = '_taskloom_csp_nonce';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * The current request's nonce, minting it on first use. Empty string when
     * there is no request (CLI, worker): callers then emit no nonce attribute,
     * which is correct — those responses carry no policy to satisfy.
     */
    public function value(): string
    {
        return $this->current() ?? $this->mint();
    }

    /**
     * The nonce if one has already been minted for this request, null
     * otherwise — never mints. Used by the header subscriber, which must not
     * conjure a nonce (and so a nonce-bearing policy) for a response that
     * emits no inline script.
     */
    public function current(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $nonce = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return \is_string($nonce) && '' !== $nonce ? $nonce : null;
    }

    private function mint(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return '';
        }

        $nonce = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (!\is_string($nonce) || '' === $nonce) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }
}
