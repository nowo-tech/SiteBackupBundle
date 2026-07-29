<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Restore;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\TestFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class RestoreOrchestratorExtendedTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private string $progressFile;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs           = new Filesystem();
        $this->projectDir   = sys_get_temp_dir() . '/nowo-sbb-rext-proj-' . uniqid('', true);
        $this->storageDir   = sys_get_temp_dir() . '/nowo-sbb-rext-store-' . uniqid('', true);
        $this->progressFile = sys_get_temp_dir() . '/nowo-sbb-rext-progress-' . uniqid('', true) . '.json';
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "version: 1\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir, $this->progressFile]);
    }

    public function testRestoreAlreadyActiveThrows(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->progressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 10.0));

        $orchestrator = $this->orchestrator($storage);
        $artifact     = $this->archiver()->create('x', 'phpunit');

        $this->expectException(RuntimeException::class);
        $orchestrator->restore($artifact, 'cli');
    }

    public function testRestoreFailurePath(): void
    {
        $storage      = new FilesystemRestoreProgressStorage($this->progressFile);
        $badArtifact  = TestFixtures::artifact('bad-id');
        $orchestrator = $this->orchestrator($storage);

        try {
            $orchestrator->restore($badArtifact, 'cli');
            self::fail('Expected exception');
        } catch (RuntimeException) {
            $progress = $storage->load();
            self::assertSame(RestoreProgress::PHASE_FAILED, $progress->getPhase());
        }
    }

    public function testRestoreWithSetupMarkerAndDatabaseDump(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'echo "SELECT 1;"',
            processTimeoutSeconds: 60,
        );
        $artifact = $archiver->create('with-db', 'phpunit');

        $markers      = new SetupMarkerManager($this->projectDir . '/setup.required', $this->projectDir . '/setup.done');
        $storage      = new FilesystemRestoreProgressStorage($this->progressFile);
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $archiver,
            progressStorage: $storage,
            protectedRelativePaths: [],
            setupMarkers: $markers,
            triggerSetupAfterRestore: true,
            postRestoreSetupProfile: 'post_restore',
        );

        $progress = $orchestrator->restore($artifact, 'cli');
        self::assertSame(RestoreProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertTrue($markers->isRequiredMarked());
        self::assertFileExists($this->projectDir . '/var/site-backup/last-restore-dump.sql');
        self::assertFalse($orchestrator->isRestoreActive());
    }

    public function testClearFailedOrCompleted(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->progressFile);
        $storage->save(new RestoreProgress(phase: RestoreProgress::PHASE_FAILED, error: 'x'));
        $orchestrator = $this->orchestrator($storage);
        $orchestrator->clearFailedOrCompleted();
        self::assertSame(RestoreProgress::PHASE_IDLE, $storage->load()->getPhase());
    }

    public function testApplyManyFilesUpdatesPercent(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->fs->mkdir($this->projectDir . '/config/sub');
            file_put_contents($this->projectDir . '/config/sub/file' . $i . '.txt', "v{$i}\n");
        }

        $archiver     = $this->archiver();
        $artifact     = $archiver->create('many', 'phpunit');
        $storage      = new FilesystemRestoreProgressStorage($this->progressFile);
        $orchestrator = $this->orchestrator($storage);
        $orchestrator->restore($artifact, 'cli');
        self::assertSame(RestoreProgress::PHASE_COMPLETED, $storage->load()->getPhase());
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

    private function orchestrator(FilesystemRestoreProgressStorage $storage): RestoreOrchestrator
    {
        return new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: $storage,
            protectedRelativePaths: ['.env.local'],
        );
    }
}
