<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The MCP endpoint's authentication: `Authorization: Bearer <key>`
 * (RFC 6750, SPEC §11).
 *
 * One credential, one principal, one comparison — but deliberately NOT the
 * admin password: the key comes from TASKLOOM_MCP_API_KEY, so rotating the
 * agent credential never touches the human one, and vice versa. The old
 * HTTP Basic flow (`Authorization: Basic base64(admin:password)`) is gone;
 * the migration is documented in the README, and this authenticator answers
 * a missing/wrong bearer token with a 401 `WWW-Authenticate: Bearer`
 * challenge — not a Basic one, so the protocol tells the client what it
 * actually accepts.
 *
 * Separate firewall (security.yaml: `^/mcp`, stateless): the MCP wire
 * protocol carries no cookies a browser would keep, sessions belong to the
 * SDK's own store (the `mcp_sessions` pool), and statelessness means a
 * stolen session cookie from the UI can never be replayed against the
 * endpoint.
 *
 * `supports()` is true for every request the firewall puts here, so a
 * request without a token still gets a 401 challenge rather than falling
 * through to an unauthenticated pass; the SDK then sees a clean 401 instead
 * of a JSON-RPC-level surprise.
 */
final class McpBearerAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const string USERNAME = 'mcp';

    public function __construct(
        private readonly McpApiKey $apiKey,
    ) {
    }

    #[\Override]
    public function supports(Request $request): ?bool
    {
        return true;
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        if (!$this->apiKey->isConfigured()) {
            // Fail closed: no configured key means no MCP access.
            throw new CustomUserMessageAuthenticationException('MCP access is not configured (TASKLOOM_MCP_API_KEY).');
        }

        $presented = self::presentedKey($request);

        if ('' === $presented) {
            throw new CustomUserMessageAuthenticationException('No bearer token provided — send "Authorization: Bearer <TASKLOOM_MCP_API_KEY>".');
        }

        return new Passport(
            new UserBadge(self::USERNAME, static fn (): AdminUser => new AdminUser()),
            new CustomCredentials(
                function (string $presented): bool {
                    if (!$this->apiKey->matches($presented)) {
                        throw new CustomUserMessageAuthenticationException('Invalid bearer token.');
                    }

                    return true;
                },
                $presented,
            ),
        );
    }

    /**
     * The raw token from an `Authorization: Bearer …` header, or '' when the
     * header is absent, empty or uses another scheme. Case-insensitive
     * scheme, surrounding whitespace tolerated — the same reading the
     * portfolio's other services apply to bearer headers.
     */
    private static function presentedKey(Request $request): string
    {
        $header = $request->headers->get('Authorization');
        if (null === $header || !preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return '';
        }

        return $matches[1];
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Stateless: no session, no redirect — let the request reach the SDK.
        return null;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->challenge();
    }

    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->challenge();
    }

    private function challenge(): Response
    {
        $message = 'MCP requires a bearer token: Authorization: Bearer <TASKLOOM_MCP_API_KEY>.';

        return new Response(
            '<html><head><title>401</title></head><body><h1>401 — MCP bearer token required</h1><p>'.htmlspecialchars($message, \ENT_QUOTES).'</p></body></html>',
            401,
            [
                'WWW-Authenticate' => 'Bearer realm="task-loom mcp"',
                'Content-Type' => 'text/html; charset=UTF-8',
            ],
        );
    }
}
