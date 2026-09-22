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
 * HTTP Basic auth for the admin surface, keyed off a single env-provided
 * password (TASKLOOM_ADMIN_PASSWORD — the deployment's one secret, see
 * compose.yaml). One admin account, no user store: the SPEC's
 * "user-authenticated sessions" boundary for the approval gate (§4.3) in
 * its smallest viable form.
 *
 * Comparison is constant-time (hash_equals) — the password is compared
 * against the env value, never stored in the DB.
 */
final class AdminAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const USERNAME = 'admin';

    public function __construct(
        private readonly string $adminPassword,
    ) {
    }

    #[\Override]
    public function supports(Request $request): ?bool
    {
        // The firewall pattern (security.yaml) decides which routes are
        // protected; /health, /ready, and assets stay open (§8.4).
        return true;
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        if ('' === $this->adminPassword) {
            // Fail closed: no configured password means no admin access.
            throw new CustomUserMessageAuthenticationException('Admin access is not configured (TASKLOOM_ADMIN_PASSWORD).');
        }

        $presented = $request->getPassword() ?? '';

        return new Passport(
            new UserBadge(self::USERNAME, static fn (): AdminUser => new AdminUser()),
            new CustomCredentials(
                fn (string $presented): bool => \hash_equals($this->adminPassword, $presented),
                $presented,
            ),
        );
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue — Basic auth carries credentials on
        // every request; no session redirect dance needed.
        return null;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, ?AuthenticationException $exception): Response
    {
        return new Response(
            '<html><head><title>401</title></head><body><h1>401 — admin credentials required</h1></body></html>',
            401,
            ['WWW-Authenticate' => 'Basic realm="task-loom admin"'],
        );
    }

    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response(
            '<html><head><title>401</title></head><body><h1>401 — admin credentials required</h1></body></html>',
            401,
            ['WWW-Authenticate' => 'Basic realm="task-loom admin"'],
        );
    }
}
