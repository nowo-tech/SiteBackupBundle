<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\BackupArtifact;

use const PHP_VERSION;

final class TestFixtures
{
    public static function artifact(
        string $id = 'test-backup-1',
        ?string $label = 'test-label',
        ?string $createdBy = 'phpunit',
    ): BackupArtifact {
        return new BackupArtifact(
            id: $id,
            filename: $id . '.tar.gz',
            absolutePath: sys_get_temp_dir() . '/' . $id . '.tar.gz',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            sizeBytes: 1024,
            archiveSha256: str_repeat('a', 64),
            checksums: ['config/app.yaml' => str_repeat('b', 64)],
            meta: ['php_version' => PHP_VERSION],
            label: $label,
            createdBy: $createdBy,
        );
    }
}
