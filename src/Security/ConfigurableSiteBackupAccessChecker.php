<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_site_backup.security.access_roles.
 */
final readonly class ConfigurableSiteBackupAccessChecker implements SiteBackupAccessCheckerInterface
{
    /** @param list<string> $accessRoles */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function canAccess(?object $user): bool
    {
        // Empty access_roles = deny (fail-closed). Use allow_unauthenticated or AllowAll* for demos.
        if ($this->accessRoles === []) {
            return false;
        }

        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
