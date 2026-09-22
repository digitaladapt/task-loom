<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The user provider behind the AdminAuthenticator's UserBadge — hands out
 * the single AdminUser. No lookups, no persistence.
 *
 * @implements UserProviderInterface<AdminUser>
 */
final class AdminUserProvider implements UserProviderInterface
{
    #[\Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return new AdminUser();
    }

    #[\Override]
    public function refreshUser(UserInterface $user): AdminUser
    {
        \assert($user instanceof AdminUser);

        return $user;
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return AdminUser::class === $class;
    }
}
