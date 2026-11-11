<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Restore;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Storage\RestoreProgressStorageInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function max;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Archive hashing, tar extraction and file apply can each outlast stale_after_seconds on a very large site:
 * they must refresh the progress updatedAt (heartbeat) so a running restore is never judged abandoned.
 */
final class RestoreHeartbeatTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs         = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/nowo-sbb-hb-proj-' . uniqid('', true);
        $this->storageDir = sys_get_temp_dir() . '/nowo-sbb-hb-store-' . uniqid('', true);
        $this->fs->mkdir($this->projectDir . '/config');
        for ($i = 0; $i < 12; ++$i) {
            file_put_contents($this->projectDir . '/config/file' . $i . '.yaml', "v: {$i}\n");
        }
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->projectDir, $this->storageDir]);
    }

    public function testLongStepsWriteHeartbeatsSoTheRestoreNeverLooksStale(): void
    {
        $artifact = $this->archiver()->create('heartbeat');

        // Mock clock: every read advances 3 s, i.e. each slice of work (chunk hashed, manifest file checked,
        // tar poll, file copied) "takes" 3 s. stale_after = 30 s → heartbeat interval = 5 s.
        $time  = 1_000_000;
        $clock = static function () use (&$time): DateTimeImmutable {
            $time += 3;

            return new DateTimeImmutable('@' . $time);
        };

        $storage      = new RecordingRestoreProgressStorage();
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: $storage,
            protectedRelativePaths: [],
            staleAfterSeconds: 30,
            clock: $clock,
        );
        $storage->onSave = static function (RestoreProgress $progress) use ($orchestrator): void {
            if ($progress->isActive()) {
                // Whatever the request checking in at this point sees, the restore is alive.
                self::assertFalse($orchestrator->isRestoreStale());
            }
        };

        $progress = $orchestrator->restore($artifact, 'cli');

        self::assertSame(RestoreProgress::PHASE_COMPLETED, $progress->getPhase());

        $started  = $progress->getStartedAt();
        $finished = $progress->getFinishedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $started);
        self::assertInstanceOf(DateTimeImmutable::class, $finished);
        // The whole restore took far longer than stale_after_seconds...
        self::assertGreaterThan(30 * 2, $finished->getTimestamp() - $started->getTimestamp());

        // ...but no two progress writes of the running restore were more than stale_after_seconds apart.
        $previous = null;
        $maxGap   = 0;
        foreach ($storage->saved as $saved) {
            $updatedAt = $saved->getUpdatedAt();
            self::assertInstanceOf(DateTimeImmutable::class, $updatedAt);
            if ($previous !== null) {
                $maxGap = max($maxGap, $updatedAt->getTimestamp() - $previous);
            }
            $previous = $updatedAt->getTimestamp();
        }
        self::assertLessThanOrEqual(30, $maxGap);

        // Heartbeats beyond the explicit advance() + appendLog() writes of each phase.
        self::assertGreaterThan(2, $storage->countPhase(RestoreProgress::PHASE_VALIDATING));
        self::assertGreaterThan(2, $storage->countPhase(RestoreProgress::PHASE_EXTRACTING));
        self::assertGreaterThan(2, $storage->countPhase(RestoreProgress::PHASE_APPLYING));
    }

    public function testHeartbeatsAreThrottled(): void
    {
        $artifact = $this->archiver()->create('throttled');

        // Frozen clock: only the explicit phase writes happen, heartbeats never fire.
        $frozen       = new DateTimeImmutable('@2000000');
        $storage      = new RecordingRestoreProgressStorage();
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: $storage,
            protectedRelativePaths: [],
            staleAfterSeconds: 0,
            clock: static fn (): DateTimeImmutable => $frozen,
        );

        $orchestrator->restore($artifact);

        self::assertSame(2, $storage->countPhase(RestoreProgress::PHASE_VALIDATING));
        self::assertSame(2, $storage->countPhase(RestoreProgress::PHASE_EXTRACTING));
        self::assertSame(2, $storage->countPhase(RestoreProgress::PHASE_APPLYING));
    }

    /**
     * REQ-DI-001: a PSR-20 clock (the framework `clock` service) works like the closure seam.
     */
    public function testPsrClockFreezesHeartbeatsToo(): void
    {
        $artifact     = $this->archiver()->create('psr-clock');
        $storage      = new RecordingRestoreProgressStorage();
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: $storage,
            protectedRelativePaths: [],
            staleAfterSeconds: 0,
            clock: new MockClock(new DateTimeImmutable('@2000000')),
        );

        $orchestrator->restore($artifact);

        self::assertSame(2, $storage->countPhase(RestoreProgress::PHASE_EXTRACTING));
    }

    public function testResetForgetsTheHeartbeatThrottle(): void
    {
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: new RecordingRestoreProgressStorage(),
            protectedRelativePaths: [],
            staleAfterSeconds: 0,
        );
        $lastWrite = new ReflectionProperty(RestoreOrchestrator::class, 'lastProgressWriteAt');
        $heartbeat = new ReflectionProperty(RestoreOrchestrator::class, 'onHeartbeat');
        $lastWrite->setValue($orchestrator, 123);
        $heartbeat->setValue($orchestrator, static function (): void {
        });

        $orchestrator->reset();

        self::assertNull($lastWrite->getValue($orchestrator));
        self::assertNull($heartbeat->getValue($orchestrator));
    }

    /**
     * FrankenPHP worker, kernel kept alive, no services resetter: one orchestrator serves many restores. The caller's
     * heartbeat closure (it holds the operation lock) and the throttle never outlive the restore that set them, and a
     * later restore never calls an earlier caller's closure.
     */
    public function testKeptAliveOrchestratorKeepsNoHeartbeatBetweenRestores(): void
    {
        $time         = 1_000_000;
        $storage      = new RecordingRestoreProgressStorage();
        $orchestrator = new RestoreOrchestrator(
            projectDir: $this->projectDir,
            archiver: $this->archiver(),
            progressStorage: $storage,
            protectedRelativePaths: [],
            staleAfterSeconds: 30,
            clock: static function () use (&$time): DateTimeImmutable {
                $time += 3;

                return new DateTimeImmutable('@' . $time);
            },
        );
        $lastWrite = new ReflectionProperty(RestoreOrchestrator::class, 'lastProgressWriteAt');
        $heartbeat = new ReflectionProperty(RestoreOrchestrator::class, 'onHeartbeat');
        $artifact  = $this->archiver()->create('worker');
        /** @var array<int, int> $beats */
        $beats = [];

        for ($run = 0; $run < 5; ++$run) {
            $before      = $beats;
            $beats[$run] = 0;
            $orchestrator->restore($artifact, 'cli', static function () use (&$beats, $run): void {
                ++$beats[$run];
            });
            self::assertGreaterThan(0, $beats[$run], 'run ' . $run . ' got its own heartbeats');
            foreach ($before as $earlier => $count) {
                self::assertSame($count, $beats[$earlier], 'run ' . $run . ' never called the closure of run ' . $earlier);
            }
            self::assertNull($heartbeat->getValue($orchestrator), 'closure dropped after run ' . $run);
            self::assertNull($lastWrite->getValue($orchestrator), 'throttle dropped after run ' . $run);
        }

        // Refused (a restore is already running): nothing of the refused caller is kept either.
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, updatedAt: new DateTimeImmutable('@4000000000')));
        try {
            $orchestrator->restore($artifact, 'cli', static function (): void {
            });
            self::fail('A running restore must refuse another one.');
        } catch (RuntimeException) {
        }
        self::assertNull($heartbeat->getValue($orchestrator));
    }

    public function testArchiverCallsTheHeartbeatWhileHashingAndExtracting(): void
    {
        $archiver = $this->archiver();
        $artifact = $archiver->create('beats');

        $verifyBeats = 0;
        $result      = $archiver->verifyIntegrity($artifact, static function () use (&$verifyBeats): void {
            ++$verifyBeats;
        });
        self::assertTrue($result['ok']);
        // archive chunk + tar start + one per manifest entry (12 files) at least
        self::assertGreaterThanOrEqual(14, $verifyBeats);

        $extractBeats = 0;
        $destination  = sys_get_temp_dir() . '/nowo-sbb-hb-extract-' . uniqid('', true);
        $archiver->extractTo($artifact, $destination, static function () use (&$extractBeats): void {
            ++$extractBeats;
        });
        self::assertFileExists($destination . '/config/file0.yaml');
        self::assertGreaterThanOrEqual(1, $extractBeats);
        $this->fs->remove($destination);
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

/**
 * In-memory progress storage that records every write.
 */
final class RecordingRestoreProgressStorage implements RestoreProgressStorageInterface
{
    /** @var list<RestoreProgress> */
    public array $saved = [];

    /** @var (callable(RestoreProgress): void)|null */
    public $onSave;

    private RestoreProgress $current;

    public function __construct()
    {
        $this->current = new RestoreProgress();
    }

    public function load(): RestoreProgress
    {
        return $this->current;
    }

    public function save(RestoreProgress $progress): void
    {
        $this->current = $progress;
        $this->saved[] = $progress;
        if ($this->onSave !== null) {
            ($this->onSave)($progress);
        }
    }

    public function countPhase(string $phase): int
    {
        $count = 0;
        foreach ($this->saved as $progress) {
            if ($progress->isActive() && $progress->getPhase() === $phase) {
                ++$count;
            }
        }

        return $count;
    }
}
