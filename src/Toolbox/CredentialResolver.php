<?php

declare(strict_types=1);

namespace App\Toolbox;

/**
 * Turns an McpServer's `cred_var` — the NAME of an environment variable,
 * never a secret value (SPEC §7) — into the HTTP headers that authenticate a
 * request to that server.
 *
 * This is the env-var → `Authorization` header conversion the registry has
 * always documented but never implemented: `cred_var` was persisted and
 * displayed, while the readers and the executor built bare transports with no
 * credentials, so a secured server rejected every sync with a 401.
 *
 * The value is read from the process environment at call time and never
 * stored, logged, or embedded in a thrown message — the same rule the readers
 * and executor already carry (see ServerReader).
 *
 * Two value shapes are accepted, so the same mechanism covers a plain API key
 * and a fully-spelled header value:
 *
 *   WEATHER_API_KEY=abc123          → Authorization: Bearer abc123
 *   WEATHER_API_KEY="Bearer abc123" → Authorization: Bearer abc123 (verbatim)
 *
 * The "contains a space" test is the discriminator: an unadorned token has no
 * scheme, anything with a space is treated as a complete `Authorization`
 * value (`Bearer …`, `Basic …`, `Token …`).
 */
final readonly class CredentialResolver
{
    public const string HEADER = 'Authorization';

    /**
     * Headers authenticating requests to a server, keyed by header name.
     *
     * Empty when the server declares no credential — an unsecured server is
     * the common case, not an error.
     *
     * @return array<string, string>
     *
     * @throws CredentialResolutionException when `cred_var` names an env var
     *                                       that is unset or empty (fail loudly,
     *                                       never send an unauthenticated request
     *                                       a secured server will only 401)
     */
    public function headersFor(?string $credVar): array
    {
        if (null === $credVar || '' === trim($credVar)) {
            return [];
        }

        $name = trim($credVar);
        $value = self::readEnv($name);

        if (null === $value || '' === trim($value)) {
            // The message carries the env var NAME only — never its value,
            // which by definition we do not have.
            throw new CredentialResolutionException(\sprintf('Credential env var "%s" is not set (or empty); cannot authenticate to the server.', $name));
        }

        return [self::HEADER => self::toAuthorizationValue($value)];
    }

    /**
     * `Bearer <token>` for a bare token, verbatim for a value that already
     * spells its scheme.
     */
    private static function toAuthorizationValue(string $value): string
    {
        $value = trim($value);

        return str_contains($value, ' ') ? $value : 'Bearer '.$value;
    }

    /**
     * Read an environment variable by name, mirroring the lookup order
     * Symfony's own `%env(...)%` processor uses (`$_ENV` → `$_SERVER` →
     * `getenv()`), so a value defined any of the ways a deployment defines it
     * is found.
     */
    private static function readEnv(string $name): ?string
    {
        // HTTP_* names are never surfaced through $_SERVER (header injection
        // hardening, same rule Symfony applies) — a credential must not be
        // settable by an inbound request header.
        if (!str_starts_with($name, 'HTTP_') && isset($_ENV[$name]) && \is_string($_ENV[$name])) {
            return $_ENV[$name];
        }

        if (!str_starts_with($name, 'HTTP_') && isset($_SERVER[$name]) && \is_string($_SERVER[$name])) {
            return $_SERVER[$name];
        }

        $value = getenv($name);

        return false === $value ? null : $value;
    }
}
