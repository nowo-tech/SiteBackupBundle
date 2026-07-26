<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Restore;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RestoreOrchestratorTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private string $progressFile;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs           = new Filesystem();
        $this->projectDir   = sys_get_temp_dir() . '/nowo-sbb-rproj-' . uniqid('', true);
        $this->storageDir   = sys_get_temp_dir() . '/nowo-sbb-rstore-' . uniqid('', true);
        $this->progressFile = sys_get_temp_dir() . '/nowo-sbb-progress-' . uniqid('', true) . '.json';
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "version: 1\n");
        file_put_contents($this->projectDir . '/.env.local', "SECRET=keep\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir, $this->progressFile]);
    }

    public function testRestoreAppliesFilesAndProtectsEnvLocal(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        $artifact = $archiver->create();

        // Mutate project after backup
        file_put_contents($this->projectDir . '/config/app.yaml', "version: 2\n");
        file_put_contents($this->projectDir . '/.env.local', "SECRET=changed\n");

        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $archiver,
            progressStorage: new FilesystemRestoreProgressStorage($this->progressFile),
            protectedRelativePaths: ['.env.local', 'var/site-backup'],
        );

        $progress = $orchestrator->restore($artifact, 'phpunit');
        self::assertSame(RestoreProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertSame("version: 1\n", file_get_contents($this->projectDir . '/config/app.yaml'));
        self::assertSame("SECRET=changed\n", file_get_contents($this->projectDir . '/.env.local'));
        self::assertFalse($orchestrator->isRestoreActive());
    }
}
