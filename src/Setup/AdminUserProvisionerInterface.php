<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * @deprecated since 1.35.0, implement {@see AdminAccountProvisionerInterface} (typed {@see AdminUserData}); removed in 2.0
 */
interface AdminUserProvisionerInterface
{
    public function adminExists(): bool;

    /**
     * @param array{email: string, password: string, roles?: list<string>} $data
     */
    public function createAdmin(array $data): void;
}
