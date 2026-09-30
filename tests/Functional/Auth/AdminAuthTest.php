<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Security\AdminUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin sign-in surface (SPEC §4.3): the password form, the seamless
 * localStorage re-login, the sign-out, and the MCP endpoint's bearer key —
 * the whole credential story the browser and external agents see.
 *
 * These go through real HTTP with real sessions and real CSRF tokens,
 * because the properties at stake are protocol-level: a session must exist
 * after a form POST and not after a wrong password, the re-login endpoint
 * must demand a CSRF token, and the MCP firewall must speak Bearer (and
 * only Bearer).
 */
final class AdminAuthTest extends WebTestCase
{
    private const string PASSWORD = 'test-admin-password';
    private const string MCP_KEY = 'test-mcp-api-key';

    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    public function testAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testTheLoginPageRendersAFormWithACsrfToken(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sign in', $content);
        self::assertStringContainsString('name="password"', $content);
        self::assertMatchesRegularExpression('/name="_token"\\s+value="[^"]+"/', $content);
    }

    public function testTheCorrectPasswordEstablishesASession(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/login', ['_token' => $token, 'password' => self::PASSWORD]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testAWrongPasswordDoesNotEstablishASession(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/login', ['_token' => $token, 'password' => 'wrong-password']);
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));

        // The failure is stored and shown on the next form render.
        $this->client->request('GET', '/login');
        self::assertStringContainsString(
            'Incorrect password',
            (string) $this->client->getResponse()->getContent(),
        );

        // And the visitor still has no session.
        $this->client->request('GET', '/');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testSigningInRequiresACsrfToken(): void
    {
        $this->client->request('POST', '/login', ['password' => self::PASSWORD]);

        // A missing/invalid CSRF token is an authentication failure, not a
        // session: the authenticator redirects back to the form.
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));

        $this->client->request('GET', '/');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    /**
     * The seamless path: a valid CSRF token plus the password trades for a
     * session without a form POST — this is what the stored-password script
     * calls.
     */
    public function testTheApiKeyEndpointSignsInWithACsrfToken(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request(
            'POST',
            '/login/api-key',
            ['password' => self::PASSWORD],
            server: ['HTTP_X_CSRF_TOKEN' => $token],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"ok":true', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testTheApiKeyEndpointRejectsAMissingCsrfToken(): void
    {
        $this->client->request('GET', '/login');

        $this->client->request('POST', '/login/api-key', ['password' => self::PASSWORD]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testTheApiKeyEndpointRejectsTheWrongPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request(
            'POST',
            '/login/api-key',
            ['password' => 'wrong-password'],
            server: ['HTTP_X_CSRF_TOKEN' => $token],
        );

        // 401, so the script knows to clear the stale stored value.
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testSigningOutEndsTheSession(): void
    {
        $this->client->loginUser(new AdminUser());

        // The sign-out form on any page carries the logout CSRF token.
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[data-signout] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token, 'the signed-in pages offer a sign-out form');

        $this->client->request('POST', '/logout', ['_csrf_token' => $token]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        // The session is gone: the next visit asks for credentials again.
        $this->client->request('GET', '/');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testSigningOutRequiresACsrfToken(): void
    {
        $this->client->loginUser(new AdminUser());

        $this->client->request('POST', '/logout');

        // Refused (403 from the logout listener), and the session survives.
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testTheMcpEndpointAcceptsTheBearerKey(): void
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::MCP_KEY,
        ], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"auth-test","version":"1"}}}');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheMcpEndpointRejectsTheWrongBearerKey(): void
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer not-the-key',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('Bearer realm="task-loom mcp"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    /**
     * The old HTTP Basic flow is gone: the MCP endpoint no longer reads
     * `Authorization: Basic`. A client still sending the base64 admin:password
     * pair must be refused (and told the challenge is Bearer).
     */
    public function testTheMcpEndpointRejectsHttpBasic(): void
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('admin:'.self::PASSWORD),
        ], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('Bearer realm="task-loom mcp"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    /**
     * The UI session is not an MCP credential: a browser cookie (or a UI
     * session established by the form) must not open the endpoint. The mcp
     * firewall is stateless for exactly this reason.
     */
    public function testAUiSessionDoesNotAuthenticateTheMcpEndpoint(): void
    {
        $this->client->loginUser(new AdminUser());

        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testHealthStaysPublic(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
    }
}
