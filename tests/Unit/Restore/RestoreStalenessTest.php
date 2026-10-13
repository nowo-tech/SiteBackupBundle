<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Restore;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function implode;
use function sys_get_temp_dir;
use function uniqid;

/**
 * A worker that dies mid-restore leaves active=true: without a staleness check every request is a 503 forever.
 */
final class RestoreStalenessTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private string $progressFile;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs           = new Filesystem();
        $this->projectDir   = sys_get_temp_dir() . '/nowo-sbb-stale-proj-' . uniqid('', true);
        $this->storageDir   = sys_get_temp_dir() . '/nowo-sbb-stale-store-' . uniqid('', true);
        $this->progressFile = sys_get_temp_dir() . '/nowo-sbb-stale-progress-' . uniqid('', true) . '.json';
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "v: 1\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir, $this->progressFile]);
    }

    public function testRecentActiveRestoreIsActive(): void
    {
        $storage      = $this->storageWith(new DateTimeImmutable('-10 seconds'));
        $orchestrator = $this->orchestrator($storage, 60);

        self::assertTrue($orchestrator->isRestoreActive());
        self::assertFalse($orchestrator->isRestoreStale());
    }

    public function testAbandonedRestoreIsNoLongerActive(): void
    {
        $storage      = $this->storageWith(new DateTimeImmutable('-2 hours'));
        $orchestrator = $this->orchestrator($storage, 60);

        self::assertFalse($orchestrator->isRestoreActive());
        self::assertTrue($orchestrator->isRestoreStale());
        // Read-only: the stored progress is left untouched for diagnosis.
        self::assertTrue($storage->load()->isActive());
    }

    public function testStartedAtIsUsedWhenUpdatedAtIsMissing(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->progressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_EXTRACTING, startedAt: new DateTimeImmutable('-2 hours')));

        self::assertFalse($this->orchestrator($storage, 60)->isRestoreActive());
    }

    public function testWithoutTimestampsOrWithStalenessDisabledTheRestoreStaysActive(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->progressFile);
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING));
        self::assertTrue($this->orchestrator($storage, 60)->isRestoreActive());

        $old = $this->storageWith(new DateTimeImmutable('-2 hours'));
        self::assertTrue($this->orchestrator($old, 0)->isRestoreActive());
        self::assertTrue($this->orchestrator($old, null)->isRestoreActive());
        self::assertFalse($this->orchestrator($old, null)->isRestoreStale());
    }

    public function testFinishedRestoreIsNeitherActiveNorStale(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->progressFile);
        $storage->save(new RestoreProgress(active: false, phase: RestoreProgress::PHASE_COMPLETED, updatedAt: new DateTimeImmutable('-2 hours')));
        $orchestrator = $this->orchestrator($storage, 60);

        self::assertFalse($orchestrator->isRestoreActive());
        self::assertFalse($orchestrator->isRestoreStale());
    }

    public function testNewRestoreReplacesAnAbandonedOneAndLogsIt(): void
    {
        $storage      = $this->storageWith(new DateTimeImmutable('-2 hours'));
        $orchestrator = $this->orchestrator($storage, 60);

        $progress = $orchestrator->restore($this->archiver()->create('again'), 'cli');

        self::assertSame(RestoreProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertStringContainsString('Previous restore of backup dead-backup abandoned', implode("\n", $progress->getLog()));
    }

    public function testRecentActiveRestoreStillBlocksANewOne(): void
    {
        $storage      = $this->storageWith(new DateTimeImmutable());
        $orchestrator = $this->orchestrator($storage, 60);

        $this->expectException(RuntimeException::class);
        $orchestrator->restore($this->archiver()->create('blocked'), 'cli');
    }

    private function storageWith(DateTimeImmutable $updatedAt): FilesystemRestoreProgressStorage
    {
        $storage = new FilesystemRestoreProgressStorage($this->progressFile);
        $storage->save(new RestoreProgress(
            active: true,
            phase: RestoreProgress::PHASE_APPLYING,
            percent: 60.0,
            backupId: 'dead-backup',
            startedAt: $updatedAt,
            updatedAt: $updatedAt,
        ));

        return $storage;
    }

    private function orchestrator(FilesystemRestoreProgressStorage $storage, ?int $staleAfter): RestoreOrchestrator
    {
        return new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: $storage,
            protectedRelativePaths: [],
            staleAfterSeconds: $staleAfter,
        );
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
