<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

interface AdminUserProvisionerInterface
{
    public function adminExists(): bool;

    /**
     * @param array{email: string, password: string, roles?: list<string>} $data
     */
    public function createAdmin(array $data): void;
}
