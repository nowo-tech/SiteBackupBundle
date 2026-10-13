<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Restore;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function array_keys;
use function file_get_contents;
use function file_put_contents;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

/**
 * storage_dir / progress_file / history_file configured inside the project but outside var/site-backup/:
 * archives must not nest previous archives nor runtime state, and restores must not overwrite them.
 */
final class RuntimeStateProtectionTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-state-' . uniqid('', true);
        $this->fs->mkdir([$this->projectDir . '/config', $this->projectDir . '/state']);
        file_put_contents($this->projectDir . '/config/app.yaml', "v: 1\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);
    }

    public function testWholeProjectBackupExcludesConfiguredStorageAndStateFiles(): void
    {
        file_put_contents($this->projectDir . '/state/restore-progress.json', '{}');
        file_put_contents($this->projectDir . '/state/history.jsonl', "{}\n");
        file_put_contents($this->projectDir . '/state/keep.txt', 'keep');

        $archiver = $this->archiver(withRuntimeState: true);
        self::assertSame(
            ['backups', 'state/restore-progress.json', 'state/history.jsonl'],
            $archiver->getRuntimeStateRelativePaths(),
        );

        $first  = $archiver->create('first');
        $second = $archiver->create('second');

        $files = array_keys($second->getChecksums());
        self::assertContains('config/app.yaml', $files);
        self::assertContains('state/keep.txt', $files);
        self::assertNotContains('state/restore-progress.json', $files);
        self::assertNotContains('state/history.jsonl', $files);
        foreach ($files as $file) {
            self::assertStringStartsNotWith('backups/', $file, 'previous archive ' . $first->getFilename() . ' nested into the next one');
        }
    }

    public function testWithoutRuntimeStatePathsTheStorageDirStillNeverNests(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->projectDir . '/backups',
            includePaths: [],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        $archiver->create('first');

        foreach (array_keys($archiver->create('second')->getChecksums()) as $file) {
            self::assertStringStartsNotWith('backups/', $file);
        }
    }

    public function testRestoreDoesNotOverwriteConfiguredProgressAndHistoryFiles(): void
    {
        // Archive taken by a host that did not protect its state files (e.g. created before v1.19).
        file_put_contents($this->projectDir . '/state/history.jsonl', "old-history\n");
        file_put_contents($this->projectDir . '/state/restore-progress.json', '{"old":true}');
        $legacy = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: sys_get_temp_dir() . '/nowo-sbb-state-store-' . uniqid('', true),
            includePaths: ['config', 'state'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        $artifact = $legacy->create('legacy');
        self::assertContains('state/history.jsonl', array_keys($artifact->getChecksums()));

        // Live state moved on since the backup.
        file_put_contents($this->projectDir . '/state/history.jsonl', "live-history\n");
        file_put_contents($this->projectDir . '/config/app.yaml', "v: 2\n");

        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(withRuntimeState: true),
            progressStorage: new FilesystemRestoreProgressStorage($this->projectDir . '/state/restore-progress.json'),
            protectedRelativePaths: [],
        );
        $progress = $orchestrator->restore($artifact, 'phpunit');

        self::assertSame(RestoreProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertSame("v: 1\n", file_get_contents($this->projectDir . '/config/app.yaml'));
        self::assertSame("live-history\n", file_get_contents($this->projectDir . '/state/history.jsonl'));
        self::assertNotSame('{"old":true}', file_get_contents($this->projectDir . '/state/restore-progress.json'));
        self::assertContains('state/history.jsonl', $this->skippedPaths($progress));
        self::assertContains('state/restore-progress.json', $this->skippedPaths($progress));

        $this->fs->remove($artifact->getAbsolutePath());
    }

    /**
     * @return list<string>
     */
    private function skippedPaths(RestoreProgress $progress): array
    {
        $skipped = [];
        foreach ($progress->getLog() as $line) {
            $pos = strpos($line, 'Skipped protected path: ');
            if ($pos !== false) {
                $skipped[] = substr($line, $pos + strlen('Skipped protected path: '));
            }
        }

        return $skipped;
    }

    private function archiver(bool $withRuntimeState): BackupArchiver
    {
        return new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->projectDir . '/backups',
            includePaths: [],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
            runtimeStatePaths: $withRuntimeState
                ? [$this->projectDir . '/state/restore-progress.json', 'state/history.jsonl', sys_get_temp_dir() . '/outside.json']
                : [],
        );
    }
}
