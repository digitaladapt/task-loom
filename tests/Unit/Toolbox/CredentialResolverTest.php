<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Toolbox\CredentialResolutionException;
use App\Toolbox\CredentialResolver;
use PHPUnit\Framework\TestCase;

/**
 * The env-var → Authorization header conversion (SPEC §7).
 *
 * Before this existed, `cred_var` was persisted and displayed but nothing
 * read it: both client call sites built bare transports, so a secured MCP
 * server rejected every catalog sync and every tool call with a 401. These
 * tests pin the conversion and, just as importantly, the two safety
 * properties — a value never appears in a thrown message, and a missing
 * variable fails loudly instead of sending an unauthenticated request.
 */
final class CredentialResolverTest extends TestCase
{
    private const ENV_NAME = 'TASKLOOM_TEST_MCP_CREDENTIAL';

    private CredentialResolver $resolver; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new CredentialResolver();
        $this->forget();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->forget();
    }

    private function forget(): void
    {
        unset($_ENV[self::ENV_NAME], $_SERVER[self::ENV_NAME]);
        putenv(self::ENV_NAME);
    }

    private function setEnv(string $value): void
    {
        // $_ENV is the first lookup the resolver (and Symfony's env processor)
        // consults; set all three so the test is order-independent.
        $_ENV[self::ENV_NAME] = $value;
        $_SERVER[self::ENV_NAME] = $value;
        putenv(self::ENV_NAME.'='.$value);
    }

    public function testNoCredVarYieldsNoHeaders(): void
    {
        self::assertSame([], $this->resolver->headersFor(null));
    }

    public function testBlankCredVarYieldsNoHeaders(): void
    {
        self::assertSame([], $this->resolver->headersFor(''));
        self::assertSame([], $this->resolver->headersFor('   '));
    }

    public function testBareTokenBecomesBearerHeader(): void
    {
        $this->setEnv('abc123');

        self::assertSame(
            ['Authorization' => 'Bearer abc123'],
            $this->resolver->headersFor(self::ENV_NAME),
        );
    }

    public function testValueWithSchemeIsUsedVerbatim(): void
    {
        // An operator who spells "Bearer …" (or "Basic …"/"Token …") gets
        // exactly what they wrote — no double prefix.
        $this->setEnv('Bearer already-schemed');

        self::assertSame(
            ['Authorization' => 'Bearer already-schemed'],
            $this->resolver->headersFor(self::ENV_NAME),
        );
    }

    public function testNonBearerSchemeIsPreserved(): void
    {
        $this->setEnv('Basic dXNlcjpwYXNz');

        self::assertSame(
            ['Authorization' => 'Basic dXNlcjpwYXNz'],
            $this->resolver->headersFor(self::ENV_NAME),
        );
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $this->setEnv('  spaced-token  ');

        self::assertSame(
            ['Authorization' => 'Bearer spaced-token'],
            $this->resolver->headersFor(self::ENV_NAME),
        );
    }

    public function testMissingEnvVarFailsLoudly(): void
    {
        // Fail closed: never send an unauthenticated request to a server the
        // registry says needs a credential.
        $this->expectException(CredentialResolutionException::class);
        $this->expectExceptionMessageMatches('/TASKLOOM_TEST_MCP_CREDENTIAL/');

        $this->resolver->headersFor(self::ENV_NAME);
    }

    public function testEmptyEnvVarFailsLoudly(): void
    {
        $this->setEnv('');

        $this->expectException(CredentialResolutionException::class);

        $this->resolver->headersFor(self::ENV_NAME);
    }

    public function testExceptionNeverLeaksTheValue(): void
    {
        // The message is recorded on the server row and surfaced in the admin
        // UI, so a value in it would be a value in the ledger. Use a missing
        // var (no value to leak) and assert the message is name-only.
        try {
            $this->resolver->headersFor(self::ENV_NAME);
            self::fail('Expected a CredentialResolutionException.');
        } catch (CredentialResolutionException $e) {
            self::assertStringContainsString(self::ENV_NAME, $e->getMessage());
            self::assertStringNotContainsString('Bearer', $e->getMessage());
        }
    }

    public function testHttpPrefixedNameIsNotReadFromServerSuperglobal(): void
    {
        // A credential must not be settable by an inbound request header;
        // $_SERVER is not consulted for HTTP_* names (same hardening Symfony
        // applies to its own env lookup).
        $_SERVER['HTTP_X_LEAKED_KEY'] = 'attacker-controlled';

        try {
            $this->expectException(CredentialResolutionException::class);
            $this->resolver->headersFor('HTTP_X_LEAKED_KEY');
        } finally {
            unset($_SERVER['HTTP_X_LEAKED_KEY']);
        }
    }
}
