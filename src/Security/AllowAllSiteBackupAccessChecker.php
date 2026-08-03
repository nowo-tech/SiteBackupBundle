<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

/**
 * Used when security.allow_unauthenticated is true (local demos / trusted networks only).
 */
final class AllowAllSiteBackupAccessChecker implements SiteBackupAccessCheckerInterface
{
    public function canAccess(?object $user): bool
    {
        return true;
    }
}
