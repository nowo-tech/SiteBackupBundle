<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;

/**
 * A host admin provisioner on the deprecated array port only.
 */
final class LegacyProvisionerFixture implements AdminUserProvisionerInterface
{
    public function adminExists(): bool
    {
        return false;
    }

    public function createAdmin(array $data): void
    {
    }
}
