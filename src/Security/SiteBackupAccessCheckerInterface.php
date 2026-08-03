<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Security;

/**
 * Role / custom access control for the site-backup admin panel (REQ-UI-002).
 * Complements the optional ops password gate ({@see SiteBackupAccessGateInterface}).
 */
interface SiteBackupAccessCheckerInterface
{
    public function canAccess(?object $user): bool;
}
