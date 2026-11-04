<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\BackupFailedEvent;
use Nowo\SiteBackupBundle\Event\BackupStartedEvent;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function file_put_contents;
use function json_encode;

final class ManagerEngineTest extends TestCase
{
    private string $root;
    private string $project;
    private string $storage;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-manager-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        (new Filesystem())->mkdir([$this->project . '/config', $this->project . '/public']);
        file_put_contents($this->project . '/config/app.yaml', "a: b\n");
        file_put_contents($this->project . '/public/index.php', "<?php\n");
        file_put_contents($this->project . '/.env.local', 'CANARY-env');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testEventsLockRefreshAndOptionsFlow(): void
    {
        $lock     = new BackupOperationLock($this->storage, new LockFactory(new InMemoryStore()));
        $manager  = $this->manager(lock: $lock);
        $progress = [];

        $artifact = $manager->createBackup(new BackupOptions(
            includePaths: ['config'],
            format: ArchiveFormat::TarGz,
            label: 'CANARY-label',
            createdBy: 'scheduler',
            heartbeat: static function (BackupProgress $p) use (&$progress): void {
                $progress[] = $p->phase;
            },
        ));

        self::assertSame(['config/app.yaml'], array_keys($artifact->getChecksums()));
        self::assertNotSame([], $progress);
        self::assertCount(2, $this->events);
        self::assertInstanceOf(BackupStartedEvent::class, $this->events[0]);
        self::assertInstanceOf(BackupCreatedEvent::class, $this->events[1]);
        self::assertSame($this->events[0]->getOperationId(), $this->events[1]->getOperationId());
        self::assertSame('scheduler', $this->events[0]->getActor());
        self::assertSame('tar.gz', $this->events[0]->getOptions()['format']);
        self::assertSame(ArchiveFormat::TarGz, $this->events[1]->getFormat());
        self::assertSame([], $this->events[1]->getWarnings());
        self::assertStringNotContainsString('CANARY-label', (string) json_encode($this->events[0]->getOptions()));

        // The lock is released afterwards.
        $lock->acquire()->release();
    }

    public function testHeldLockRefusesEveryOperation(): void
    {
        $lock    = new BackupOperationLock($this->storage);
        $manager = $this->manager(lock: $lock);
        $held    = $lock->acquire();

        try {
            foreach ([
                static fn () => $manager->createBackup(),
                static fn () => $manager->deleteBackup('x'),
                static fn () => $manager->restore('x'),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Expected BackupAlreadyRunningException');
                } catch (BackupAlreadyRunningException $e) {
                    self::assertSame(300, $e->getRetryAfterSeconds());
                }
            }
            self::assertSame([], $this->events, 'nothing starts while locked');
        } finally {
            $held->release();
            $held->release();
            $held->refresh();
        }

        self::assertFalse($lock->usesSharedStore());
        self::assertTrue((new BackupOperationLock($this->storage, new LockFactory(new InMemoryStore())))->usesSharedStore());
        $manager->createBackup();
    }

    public function testSharedStoreLockConflict(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $first   = (new BackupOperationLock($this->storage, $factory, 'k'))->acquire();
        $first->refresh();

        try {
            $this->expectException(BackupAlreadyRunningException::class);
            (new BackupOperationLock($this->storage, $factory, 'k'))->acquire();
        } finally {
            $first->release();
        }
    }

    public function testFailureDispatchesARedactedFailedEvent(): void
    {
        $archiver = new BackupArchiver($this->project, $this->storage, ['config'], [], 'echo "mysql://app:CANARY-pw@db/x down" >&2; exit 4', 60);
        $logger   = new RecordingLogger();
        $manager  = $this->manager(archiver: $archiver, logger: $logger);

        try {
            $manager->createBackup(new BackupOptions(createdBy: 'cron'));
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }

        self::assertCount(2, $this->events);
        $failed = $this->events[1];
        self::assertInstanceOf(BackupFailedEvent::class, $failed);
        self::assertStringContainsString('mysql://app:***@db/x', $failed->getMessage());
        self::assertStringNotContainsString('CANARY-pw', (string) json_encode([$failed->getMessage(), $failed->getOptions(), $logger->records]));
        self::assertSame('cron', $failed->getActor());
        $started = $this->events[0];
        self::assertInstanceOf(BackupStartedEvent::class, $started);
        self::assertSame($started->getOperationId(), $failed->getOperationId());
        self::assertStringEndsWith('DumpFailedException', $failed->getExceptionClass());
    }

    public function testLocalRetentionAndDefaultOptions(): void
    {
        $clock   = new MockClock('2026-11-04 10:00:00');
        $manager = $this->manager(retention: new RetentionPolicy(keepLast: 2), clock: $clock, format: ArchiveFormat::SqlGz, level: 3);

        $defaults = $manager->defaultOptions('l', 'a');
        self::assertSame(ArchiveFormat::SqlGz, $defaults->format);
        self::assertFalse($defaults->includeFiles);
        self::assertSame(3, $defaults->compressionLevel);

        $tarManager = $this->manager(retention: new RetentionPolicy(keepLast: 2), clock: $clock);
        $ids        = [];
        for ($i = 0; $i < 4; ++$i) {
            $ids[] = $tarManager->createBackup()->getId();
            $clock->sleep(60);
        }

        $left = array_map(static fn ($a) => $a->getId(), $tarManager->listBackups());
        self::assertSame([$ids[3], $ids[2]], $left);
        $deletes = 0;
        foreach ($this->events as $event) {
            $deletes += $event instanceof BackupDeletedEvent ? 1 : 0;
        }
        self::assertSame(2, $deletes);
        self::assertCount(4, array_filter($this->events, static fn ($e) => $e instanceof BackupCreatedEvent));
    }

    public function testEmptyRetentionPolicyKeepsEverything(): void
    {
        $manager = $this->manager(retention: new RetentionPolicy());
        $manager->createBackup();
        $manager->createBackup();

        self::assertCount(2, $manager->listBackups());
    }

    private function manager(
        ?BackupArchiver $archiver = null,
        ?BackupOperationLock $lock = null,
        ?RetentionPolicy $retention = null,
        ?MockClock $clock = null,
        ArchiveFormat $format = ArchiveFormat::TarGz,
        int $level = 6,
        ?RecordingLogger $logger = null,
    ): SiteBackupManager {
        $archiver ??= new BackupArchiver($this->project, $this->storage, ['config', 'public', '.env.local'], [], null, 60, clock: $clock ?? new MockClock());
        $events = &$this->events;

        return new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: new MemoryHistoryStorage(),
            eventDispatcher: new class($events) implements EventDispatcherInterface {
                /** @var list<object> */
                private array $events;

                /** @param list<object> $events */
                public function __construct(array &$events)
                {
                    $this->events = &$events;
                }

                public function dispatch(object $event): object
                {
                    $this->events[] = $event;

                    return $event;
                }

                /** @return list<object> */
                public function all(): array
                {
                    return $this->events;
                }
            },
            clock: $clock ?? new MockClock(),
            logger: $logger ?? new RecordingLogger(),
            operationLock: $lock,
            localRetention: $retention,
            defaultFormat: $format,
            defaultCompressionLevel: $level,
        );
    }
}
