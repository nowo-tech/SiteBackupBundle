<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Backup;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Tests\Unit\TestFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BackupArchiverExtendedTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-arch-ext-proj-' . uniqid('', true);
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-arch-ext-store-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "foo: bar\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir]);
    }

    public function testFindAndDeleteMissing(): void
    {
        $archiver = $this->archiver();
        self::assertNull($archiver->find('missing'));
        self::assertFalse($archiver->delete('missing'));
        self::assertSame([], $archiver->listArtifacts());
    }

    public function testVerifyIntegrityMissingArchive(): void
    {
        $artifact = TestFixtures::artifact();
        $archiver = $this->archiver();
        $result   = $archiver->verifyIntegrity($artifact);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('missing', strtolower($result['errors'][0]));
    }

    public function testVerifyIntegrityShaMismatch(): void
    {
        $archiver = $this->archiver();
        $artifact = $archiver->create('x', 'phpunit');
        $bad      = TestFixtures::artifact($artifact->getId());
        $result   = $archiver->verifyIntegrity($bad);
        self::assertFalse($result['ok']);
    }

    public function testDatabaseDumpCommand(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'echo "SELECT 1;"',
            processTimeoutSeconds: 60,
        );

        $artifact = $archiver->create('db', 'phpunit');
        $verify   = $archiver->verifyIntegrity($artifact);
        self::assertTrue($verify['ok'], implode('; ', $verify['errors']));
        self::assertArrayHasKey('database/dump.sql', $verify['checksums']);
    }

    public function testExcludedStoragePaths(): void
    {
        $this->fs->mkdir($this->projectDir . '/var/site-backup');
        file_put_contents($this->projectDir . '/var/site-backup/secret.txt', "secret\n");

        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['.'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );

        $artifact = $archiver->create('full', 'phpunit');
        $verify   = $archiver->verifyIntegrity($artifact);
        self::assertTrue($verify['ok'], implode('; ', $verify['errors']));
        self::assertArrayNotHasKey('var/site-backup/secret.txt', $verify['checksums']);
    }

    private function archiver(): BackupArchiver
    {
        return new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
    }
}
