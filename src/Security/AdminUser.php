<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The single admin principal for HTTP Basic auth — no user store, no
 * mutable state. ROLE_ADMIN is the entire authorization model (§4.3).
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
