<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use RuntimeException;

/**
 * Default provisioner when the app does not bind one — admin_user step fails with a clear message.
 */
final class NullAdminUserProvisioner implements AdminUserProvisionerInterface
{
    public function adminExists(): bool
    {
        return false;
    }

    public function createAdmin(array $data): void
    {
        throw new RuntimeException('No AdminUserProvisionerInterface is configured. Bind nowo_site_backup.setup.admin_provisioner to your app service.');
    }
}
