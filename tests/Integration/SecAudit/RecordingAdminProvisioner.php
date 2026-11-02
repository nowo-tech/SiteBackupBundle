<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\SecAudit;

use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;

use function is_file;
use function json_encode;

/** Host provisioner of a fresh deployment (no admin yet); records the account it creates in a file. */
final class RecordingAdminProvisioner implements AdminUserProvisionerInterface
{
    public function __construct(private readonly string $file)
    {
    }

    public function adminExists(): bool
    {
        return is_file($this->file);
    }

    public function createAdmin(array $data): void
    {
        file_put_contents($this->file, (string) json_encode(['email' => $data['email'], 'roles' => $data['roles'] ?? []]));
    }
}
