<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\AdminPassword;
use App\Security\AdminPasswordAuthenticator;
use App\Security\AdminUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The admin UI's sign-in surface (SPEC §4.3's "user-authenticated sessions",
 * made usable from a browser).
 *
 * Three pieces, one session:
 *
 *  - `GET /login` renders the password form (and reports a failed attempt,
 *    via the standard `AuthenticationUtils` error the authenticator stores).
 *  - `POST /login` is handled by `AdminPasswordAuthenticator` (the firewall
 *    intercepts it before this controller); on success the request lands
 *    back here, which redirects to wherever the session was headed.
 *  - `POST /login/api-key` is the seamless re-login: the browser keeps the
 *    password in localStorage and posts it here without a form round trip,
 *    trading it for a session. It exists so a returning visitor never sees
 *    the login page; a 401 means the stored password changed and the page
 *    should show the form.
 *
 * Both POSTs require a CSRF token minted for the login intent — the form
 * carries it as a hidden field, the seamless path sends it as the
 * `X-CSRF-Token` header. The token is fetched, not known, so a cross-site
 * script cannot mint one; combined with `SameSite=Lax` session cookies this
 * is the same-origin boundary the rest of the admin writes rely on.
 *
 * Logout is deliberately absent: `security.yaml` configures it, and Symfony
 * registers the `/logout` route and listener from that config (route name
 * `_logout_main`). A second route here would duplicate the path for no gain.
 */
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly AdminPassword $password,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Security $security,
    ) {
    }

    /**
     * The sign-in form. Visiting while already signed in goes straight on —
     * there is nothing to log in to.
     */
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->security->getUser() instanceof AdminUser) {
            return $this->redirectToRoute('app_task_list');
        }

        return $this->render('auth/login.html.twig', [
            // The last failure the authenticator stored (wrong password, bad
            // CSRF token), consumed once so a reload does not replay it.
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'configured' => $this->password->isConfigured(),
            'csrf_token' => $this->csrfTokenManager->getToken(AdminPasswordAuthenticator::CSRF_TOKEN_ID)->getValue(),
        ]);
    }

    /**
     * The authenticator's success target: the firewall has already signed
     * the session in by the time this runs, so this is purely "where was the
     * human going?" — the stored target path when the firewall saved one,
     * the task list otherwise.
     */
    #[Route('/login', name: 'app_login_check', methods: ['POST'])]
    public function loginCheck(Request $request): Response
    {
        return $this->redirect($this->targetPath($request));
    }

    /**
     * Seamless re-login: exchange the localStorage-held password for a
     * session, without a form.
     *
     * This is the endpoint `assets/auth.js` calls when the login page is
     * shown and a password is stored. It verifies the password exactly as
     * the form does (constant-time, `AdminPassword`), demands a real CSRF
     * token, and on success signs the session in programmatically —
     * `Security::login()` runs the same authenticator machinery a form POST
     * would, so the resulting token, the session cookie and the firewall's
     * strategy are identical to the interactive path.
     *
     * JSON-only, deliberately: this endpoint is for the script, not a human,
     * and a redirect response would let a `<form>` post to it from another
     * origin and sign the visitor in sideways.
     */
    #[Route('/login/api-key', name: 'app_login_api_key', methods: ['POST'])]
    public function loginWithApiKey(Request $request): JsonResponse
    {
        if (!$this->password->isConfigured()) {
            return new JsonResponse(['error' => 'Admin access is not configured (TASKLOOM_ADMIN_PASSWORD).'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid(AdminPasswordAuthenticator::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->password->matches($request->request->getString('password'))) {
            return new JsonResponse(['error' => 'Incorrect password.'], Response::HTTP_UNAUTHORIZED);
        }

        // The same session a form login would establish; the response's
        // session cookie is what the browser keeps. The authenticator's
        // success response is null (it defers to the request continuing),
        // so there is nothing to forward.
        $this->security->login(new AdminUser(), AdminPasswordAuthenticator::class, 'main');

        return new JsonResponse(['ok' => true, 'location' => $this->targetPath($request)]);
    }

    /**
     * The path the firewall stored for post-login navigation, or the task
     * list when there is none. Read from the session key the firewall writes
     * (`_security.<firewall>.target_path`, TargetPathTrait's contract).
     */
    private function targetPath(Request $request): string
    {
        $target = $request->getSession()->get('_security.main.target_path');

        return \is_string($target) && '' !== $target && str_starts_with($target, '/') ? $target : '/';
    }
}
