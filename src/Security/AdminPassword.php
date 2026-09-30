<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The deployment's single admin password (TASKLOOM_ADMIN_PASSWORD), and the
 * one place it is compared.
 *
 * One admin account, one secret, no user store: the SPEC's
 * "user-authenticated sessions" boundary for the approval gate (§4.3) in its
 * smallest viable form. The password is never stored in the database and
 * never hashed into a row — it lives in the environment, and every caller
 * that needs to verify a presented value goes through here so the comparison
 * (constant-time, `hash_equals`) exists exactly once.
 *
 * Fail closed: an unconfigured deployment (`TASKLOOM_ADMIN_PASSWORD` empty)
 * matches nothing, and `isConfigured()` lets the UI say why instead of
 * leaving the operator with a login form that can never succeed.
 *
 * This is the credential behind BOTH front doors' human half — the sign-in
 * form and the seamless localStorage re-login endpoint — which is why it is
 * a service rather than a detail of one authenticator. The MCP endpoint's
 * bearer key is separate (TASKLOOM_MCP_API_KEY): an agent's credential must
 * be revocable without rotating the human's password, and must never be the
 * password itself.
 */
final readonly class AdminPassword
{
    public function __construct(
        private string $password,
    ) {
    }

    /**
     * Whether a password has been configured at all. An empty value is not a
     * password of "" — it is "admin access is not configured", and every
     * caller must treat it as a hard refusal (fail closed).
     */
    public function isConfigured(): bool
    {
        return '' !== $this->password;
    }

    /**
     * Constant-time comparison against the configured password. False when
     * nothing is configured, so an unconfigured deployment can never be
     * entered by sending an empty value.
     */
    public function matches(string $presented): bool
    {
        return $this->isConfigured() && \hash_equals($this->password, $presented);
    }
}
