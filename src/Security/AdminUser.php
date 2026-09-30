<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The single admin principal — no user store, no mutable state. ROLE_ADMIN is
 * the entire authorization model (§4.3).
 *
 * Both the human's session login (AdminPasswordAuthenticator) and the MCP
 * endpoint's bearer key (McpBearerAuthenticator) resolve to this same
 * principal: the SPEC has one admin identity and two front doors, not two
 * roles to keep in sync. What separates them is the credential each door
 * demands, and that agents can never reach the UI's lifecycle actions
 * because those require an interactive session, not just ROLE_ADMIN.
 */
final class AdminUser implements UserInterface
{
    #[\Override]
    public function getUserIdentifier(): string
    {
        return 'admin';
    }

    /** @return list<string> */
    #[\Override]
    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }
}
