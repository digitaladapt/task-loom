<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The admin UI's sign-in: one password field, one session (SPEC §4.3, §8).
 *
 * Replaces the old HTTP Basic authenticator. The difference the operator
 * sees is the login page: visiting any protected route while signed out
 * redirects to `/login`, a successful POST establishes a session, and
 * `/logout` ends it. The browser can then keep the password in localStorage
 * (see `assets/auth.js` and the `app_login_api_key` endpoint) so a returning
 * visitor is signed in without seeing the form — the "seamless" half.
 *
 * Deliberately NOT Symfony's FormLoginAuthenticator: that authenticator
 * verifies a username+password against a hasher-backed user row, and this
 * deployment has no user store — the password comes from the environment and
 * is compared constant-time by `AdminPassword`. It also keeps the CSRF
 * check (badge) so the form cannot be driven cross-site, and returns no
 * success response so the request continues to the controller, which owns
 * the redirect target (the session's stored target path, or the task list).
 *
 * `onAuthenticationFailure` stores the failure in the session the standard
 * way (`SecurityRequestAttributes::AUTHENTICATION_ERROR`), so the login
 * template renders it through `AuthenticationUtils` — the same mechanism a
 * stock form login would use.
 */
final class AdminPasswordAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface, InteractiveAuthenticatorInterface
{
    /** The one route this authenticator handles: the sign-in form POST. */
    public const string LOGIN_PATH = '/login';

    /** CSRF token id the form and the seamless re-login endpoint share. */
    public const string CSRF_TOKEN_ID = 'login';

    private const string USERNAME = 'admin';

    public function __construct(
        private readonly AdminPassword $password,
    ) {
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && self::LOGIN_PATH === $request->getPathInfo();
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        if (!$this->password->isConfigured()) {
            // Fail closed: no configured password means no admin access.
            throw new CustomUserMessageAuthenticationException('Admin access is not configured (TASKLOOM_ADMIN_PASSWORD).');
        }

        $presented = $request->request->getString('password');

        return new Passport(
            new UserBadge(self::USERNAME, static fn (): AdminUser => new AdminUser()),
            new CustomCredentials(
                function (string $presented): bool {
                    if (!$this->password->matches($presented)) {
                        throw new CustomUserMessageAuthenticationException('Incorrect password.');
                    }

                    return true;
                },
                $presented,
            ),
            [new CsrfTokenBadge(self::CSRF_TOKEN_ID, $request->request->getString('_token'))],
        );
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // No response: the request continues to the login controller, which
        // redirects to the session's stored target path (or the task list).
        // The session token is persisted by the firewall's session strategy.
        return null;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse(self::LOGIN_PATH);
    }

    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse(self::LOGIN_PATH);
    }

    #[\Override]
    public function isInteractive(): bool
    {
        return true;
    }
}
