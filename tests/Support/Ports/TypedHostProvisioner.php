<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Setup\AdminAccountProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserData;

/**
 * DDD host provisioner on the typed port: records the emails and roles it was asked to create. No Symfony import.
 */
final class TypedHostProvisioner implements AdminAccountProvisionerInterface
{
    /** @var list<array{string, list<string>}> */
    public array $created = [];

    public function adminExists(): bool
    {
        return $this->created !== [];
    }

    public function createAdminAccount(AdminUserData $admin): void
    {
        $this->created[] = [$admin->email, $admin->roles];
    }
}
