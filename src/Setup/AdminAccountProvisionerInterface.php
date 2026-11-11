<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * Creates the first administrator from the setup wizard's `admin_user` step, typed (1.35+).
 *
 * Replaces the deprecated array form {@see AdminUserProvisionerInterface::createAdmin()}. Configure it with
 * `setup.admin_provisioner` (service id). The step runs only during the initial install: a re-opened wizard skips it.
 */
interface AdminAccountProvisionerInterface
{
    public function adminExists(): bool;

    /**
     * Throw on failure; the message is redacted before it is shown or stored.
     */
    public function createAdminAccount(AdminUserData $admin): void;
}
