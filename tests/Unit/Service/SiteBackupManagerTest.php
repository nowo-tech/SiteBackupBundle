<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Service;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Storage\BackupHistoryStorageInterface;
use Nowo\SiteBackupBundle\Storage\RestoreProgressStorageInterface;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingEventDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function array_slice;

final class SiteBackupManagerTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-mgr-proj-' . uniqid('', true);
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-mgr-store-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/config');
        file_put_contents($this->projectDir . '/config/app.yaml', "foo: bar\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir]);
    }

    public function testCreateBackupWithHistoryAndEvent(): void
    {
        $history = new InMemoryHistoryStorage();
        /** @var list<class-string> $events */
        $events  = [];
        $manager = $this->manager($history, $events);

        $artifact = $manager->createBackup('label', 'cli');
        self::assertSame('label', $artifact->getLabel());
        self::assertCount(1, $history->entries);
        self::assertSame('create', $history->entries[0]->getAction());
        self::assertContains(BackupCreatedEvent::class, $events);
    }

    public function testDeleteBackupErrors(): void
    {
        /** @var list<class-string> $events */
        $events  = [];
        $manager = $this->manager(new InMemoryHistoryStorage(), $events);
        $this->expectException(RuntimeException::class);
        $manager->deleteBackup('missing');
    }

    public function testDeleteBackupSuccess(): void
    {
        $history = new InMemoryHistoryStorage();
        /** @var list<class-string> $events */
        $events   = [];
        $manager  = $this->manager($history, $events);
        $artifact = $manager->createBackup(null, 'cli');
        $manager->deleteBackup($artifact->getId(), 'panel');
        self::assertNull($manager->getBackup($artifact->getId()));
        self::assertContains(BackupDeletedEvent::class, $events);
    }

    public function testVerifyBackupNotFound(): void
    {
        /** @var list<class-string> $events */
        $events  = [];
        $manager = $this->manager(new InMemoryHistoryStorage(), $events);
        $result  = $manager->verifyBackup('missing');
        self::assertFalse($result['ok']);
    }

    public function testRestoreSuccessAndFailure(): void
    {
        $history = new InMemoryHistoryStorage();
        /** @var list<class-string> $events */
        $events   = [];
        $manager  = $this->manager($history, $events);
        $artifact = $manager->createBackup('r', 'cli');
        $progress = $manager->restore($artifact->getId(), 'cli');
        self::assertSame(RestoreProgress::PHASE_COMPLETED, $progress->getPhase());
        self::assertContains(RestoreStartedEvent::class, $events);
        self::assertContains(RestoreCompletedEvent::class, $events);
    }

    public function testRestoreFailureDispatchesEvent(): void
    {
        $history = new InMemoryHistoryStorage();
        /** @var list<class-string> $events */
        $events   = [];
        $manager  = $this->manager($history, $events);
        $artifact = $manager->createBackup('r2', 'cli');
        file_put_contents($artifact->getAbsolutePath(), 'corrupt');

        $failManager = new SiteBackupManager(
            $this->archiver(),
            new RestoreOrchestrator(
                projectDir: $this->projectDir,
                archiver: $this->archiver(),
                progressStorage: new InMemoryRestoreProgressStorage(),
                protectedRelativePaths: ['.env.local'],
            ),
            $history,
            new RecordingEventDispatcher($events),
        );

        try {
            $failManager->restore($artifact->getId(), 'cli');
            self::fail('Expected exception');
        } catch (RuntimeException) {
            self::assertContains(RestoreFailedEvent::class, $events);
        }
    }

    public function testClearRestoreStatusAndHistory(): void
    {
        /** @var list<class-string> $events */
        $events  = [];
        $history = new InMemoryHistoryStorage();
        $manager = $this->manager($history, $events);
        $manager->clearRestoreStatus();
        self::assertSame([], $manager->history(10));
    }

    /**
     * @param list<class-string> $events
     */
    private function manager(BackupHistoryStorageInterface $history, array &$events): SiteBackupManager
    {
        return new SiteBackupManager(
            $this->archiver(),
            $this->restoreOrchestrator(),
            $history,
            new RecordingEventDispatcher($events),
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

    private function restoreOrchestrator(): RestoreOrchestrator
    {
        return new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: new InMemoryRestoreProgressStorage(),
            protectedRelativePaths: ['.env.local'],
        );
    }
}

final class InMemoryHistoryStorage implements BackupHistoryStorageInterface
{
    /** @var list<BackupHistoryEntry> */
    public array $entries = [];

    public function append(BackupHistoryEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function list(int $limit = 50): array
    {
        return array_slice(array_reverse($this->entries), 0, $limit);
    }
}

final class InMemoryRestoreProgressStorage implements RestoreProgressStorageInterface
{
    private RestoreProgress $progress;

    public function __construct()
    {
        $this->progress = new RestoreProgress();
    }

    public function load(): RestoreProgress
    {
        return $this->progress;
    }

    public function save(RestoreProgress $progress): void
    {
        $this->progress = $progress;
    }
}
