<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-OBS-001 / REQ-DI-001: destructive operations log start / success / failure without secrets; time comes from
 * the injected clock.
 */
final class SiteBackupManagerLoggingTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-log-proj-' . uniqid('', true);
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-log-store-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "foo: bar\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir]);
    }

    public function testCreateDeleteAndRestoreAreLogged(): void
    {
        $logger  = new RecordingLogger();
        $history = new InMemoryHistoryStorage();
        $clock   = new MockClock(new DateTimeImmutable('2026-03-04 05:06:07'));
        $manager = $this->manager($this->archiver(), $history, $logger, $clock);

        $artifact = $manager->createBackup('secret-label', 'alice');
        $manager->restore($artifact->getId(), 'alice');
        $manager->deleteBackup($artifact->getId(), 'alice');

        self::assertSame([
            'info SiteBackup: backup started.',
            'info SiteBackup: backup created.',
            'warning SiteBackup: restore started.',
            'info SiteBackup: restore completed.',
            'warning SiteBackup: backup deleted.',
        ], $logger->lines());
        self::assertSame(['bundle' => 'site_backup', 'action' => 'create', 'backup_id' => $artifact->getId(), 'size_bytes' => $artifact->getSizeBytes()], $logger->records[1]['context']);
        self::assertSame(['bundle' => 'site_backup', 'action' => 'delete', 'backup_id' => $artifact->getId()], $logger->records[4]['context']);

        $all = (string) json_encode($logger->records);
        self::assertStringNotContainsString('alice', $all, 'actors are not logged');
        self::assertStringNotContainsString('secret-label', $all, 'labels are not logged');

        // History timestamps come from the clock (restore_start / restore_complete / delete).
        self::assertSame('2026-03-04 05:06:07', $history->entries[1]->getOccurredAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-04 05:06:07', $history->entries[3]->getOccurredAt()->format('Y-m-d H:i:s'));
    }

    public function testFailuresAreLoggedRedacted(): void
    {
        $logger  = new RecordingLogger();
        $failing = new BackupArchiver(
            projectDir: $this->projectDir,
            storageDir: $this->storageDir,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'echo "connect mysql://app:REDACTED-TEST@db/shop refused" >&2; exit 2',
            processTimeoutSeconds: 60,
        );
        $manager = $this->manager($failing, new InMemoryHistoryStorage(), $logger);

        try {
            $manager->createBackup();
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }

        self::assertSame(['info SiteBackup: backup started.', 'error SiteBackup: backup failed.'], $logger->lines());
        $context = $logger->records[1]['context'];
        self::assertSame(RuntimeException::class, $context['exception']);
        self::assertIsString($context['error']);
        self::assertStringContainsString('mysql://app:***@db/shop', $context['error']);
        self::assertStringNotContainsString('REDACTED-TEST', (string) json_encode($logger->records));
    }

    public function testRestoreFailureIsLogged(): void
    {
        $logger   = new RecordingLogger();
        $archiver = $this->archiver();
        $manager  = $this->manager($archiver, new InMemoryHistoryStorage(), $logger);
        $artifact = $manager->createBackup();
        file_put_contents($artifact->getAbsolutePath(), 'corrupt');

        try {
            $manager->restore($artifact->getId());
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        self::assertSame('error SiteBackup: restore failed.', $logger->lines()[3]);
        self::assertSame($artifact->getId(), $logger->records[3]['context']['backup_id']);

    }

    private function manager(BackupArchiver $archiver, InMemoryHistoryStorage $history, RecordingLogger $logger, ?MockClock $clock = null): SiteBackupManager
    {
        return new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator(
                projectDir: $this->projectDir,
                archiver: $archiver,
                progressStorage: new InMemoryRestoreProgressStorage(),
                protectedRelativePaths: ['.env.local'],
            ),
            historyStorage: $history,
            clock: $clock ?? new MockClock(),
            logger: $logger,
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
