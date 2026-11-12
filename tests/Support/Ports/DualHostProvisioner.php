<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserData;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;

/**
 * Host provisioner implementing the deprecated and the 1.35 port.
 */
final class DualHostProvisioner implements AdminUserProvisionerInterface, AdminAccountProvisionerInterface
{
    public function adminExists(): bool
    {
        return false;
    }

    public function createAdmin(array $data): void
    {
    }

    public function createAdminAccount(AdminUserData $admin): void
    {
    }
}
