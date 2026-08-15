<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\ChainSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class DoctrineAndChainStorageTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/nowo-sbb-doc-store-' . uniqid('', true);
        $this->fs->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testDoctrineStorageRoundTripWithFakeConnection(): void
    {
        $conn    = new FakeDbalConnection();
        $storage = new DoctrineDbalSetupProgressStorage($conn);

        self::assertTrue($storage->isUsable());
        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $progress = new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'fresh_install',
            currentStepId: 'admin_user_6',
            percent: 66.7,
            updatedAt: new DateTimeImmutable('2026-07-29T10:05:00+00:00'),
            startedAt: new DateTimeImmutable('2026-07-29T10:00:00+00:00'),
        );
        $storage->save($progress);

        $loaded = $storage->load();
        self::assertSame(SetupProgress::PHASE_WAITING, $loaded->getPhase());
        self::assertSame('admin_user_6', $loaded->getCurrentStepId());
        self::assertSame(66.7, $loaded->getPercent());
        self::assertNotNull($loaded->getStartedAt());

        $progress2 = $progress->with(phase: SetupProgress::PHASE_COMPLETED, percent: 100.0, completedAt: new DateTimeImmutable('2026-07-29T10:10:00+00:00'));
        $storage->save($progress2);
        self::assertTrue($storage->load()->isFinished());
    }

    public function testDoctrineStorageUnusableWithoutConnection(): void
    {
        $storage = new DoctrineDbalSetupProgressStorage();
        self::assertFalse($storage->isUsable());
        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $this->expectException(RuntimeException::class);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));
    }

    public function testChainPrefersDoctrineOverFilesystem(): void
    {
        $file  = $this->dir . '/progress.json';
        $fs    = new FilesystemSetupProgressStorage($file);
        $db    = new DoctrineDbalSetupProgressStorage(new FakeDbalConnection());
        $chain = new ChainSetupProgressStorage($fs, $db);

        $fs->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'from_file', percent: 10.0));
        $db->save(new SetupProgress(phase: SetupProgress::PHASE_WAITING, currentStepId: 'from_db', percent: 50.0, startedAt: new DateTimeImmutable()));

        $loaded = $chain->load();
        self::assertSame('from_db', $loaded->getCurrentStepId());
        self::assertSame(SetupProgress::PHASE_WAITING, $loaded->getPhase());

        // Wipe file; chain still loads from DB
        @unlink($file);
        self::assertSame('from_db', $chain->load()->getCurrentStepId());
    }

    public function testChainFallsBackToFilesystemWhenDoctrineIdle(): void
    {
        $file  = $this->dir . '/progress2.json';
        $fs    = new FilesystemSetupProgressStorage($file);
        $db    = new DoctrineDbalSetupProgressStorage(new FakeDbalConnection());
        $chain = new ChainSetupProgressStorage($fs, $db);

        $fs->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'file_only', percent: 20.0));
        self::assertSame('file_only', $chain->load()->getCurrentStepId());
    }

    public function testChainSaveWritesFilesystemAndDoctrine(): void
    {
        $file  = $this->dir . '/progress3.json';
        $fs    = new FilesystemSetupProgressStorage($file);
        $db    = new DoctrineDbalSetupProgressStorage(new FakeDbalConnection());
        $chain = new ChainSetupProgressStorage($fs, $db);

        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'via_chain', percent: 33.0, startedAt: new DateTimeImmutable()));
        self::assertSame('via_chain', $fs->load()->getCurrentStepId());
        self::assertSame('via_chain', $db->load()->getCurrentStepId());
    }

    public function testChainSaveSkipsDoctrineWhenUnusable(): void
    {
        $file  = $this->dir . '/progress4.json';
        $fs    = new FilesystemSetupProgressStorage($file);
        $db    = new DoctrineDbalSetupProgressStorage();
        $chain = new ChainSetupProgressStorage($fs, $db);

        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'fs_only', percent: 10.0));
        self::assertSame('fs_only', $chain->load()->getCurrentStepId());
    }

    public function testChainLoadFallsBackWhenDoctrineThrows(): void
    {
        $file = $this->dir . '/progress5.json';
        $fs   = new FilesystemSetupProgressStorage($file);
        $fs->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'fallback', percent: 5.0));

        $throwing = new class {
            public function executeQuery(string $sql): never
            {
                throw new RuntimeException('db down');
            }

            /** @param list<mixed> $params */
            public function executeStatement(string $sql, array $params = []): never
            {
                throw new RuntimeException('db down');
            }
        };
        $db    = new DoctrineDbalSetupProgressStorage($throwing);
        $chain = new ChainSetupProgressStorage($fs, $db);

        self::assertSame('fallback', $chain->load()->getCurrentStepId());
    }

    public function testChainSaveIgnoresDoctrineWriteFailure(): void
    {
        $file = $this->dir . '/progress6.json';
        $fs   = new FilesystemSetupProgressStorage($file);
        $conn = new class {
            public function executeQuery(string $sql): object
            {
                return new class {
                    public function fetchAssociative(): false
                    {
                        return false;
                    }
                };
            }

            /** @param list<mixed> $params */
            public function executeStatement(string $sql, array $params = []): never
            {
                throw new RuntimeException('cannot write');
            }
        };
        $db    = new DoctrineDbalSetupProgressStorage($conn);
        $chain = new ChainSetupProgressStorage($fs, $db);

        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'fs_ok', percent: 1.0));
        self::assertSame('fs_ok', $fs->load()->getCurrentStepId());
    }

    public function testDoctrineLoadInvalidPayloadAndEmptyPayload(): void
    {
        $conn = new FakeDbalConnection();
        $conn->seedRow(['phase' => 'running', 'profile' => 'x', 'current_step_id' => null, 'percent' => 1, 'started_at' => null, 'updated_at' => null, 'completed_at' => null, 'payload' => '{not-json']);
        $storage = new DoctrineDbalSetupProgressStorage($conn);
        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $conn2 = new FakeDbalConnection();
        $conn2->seedRow(['phase' => 'running', 'profile' => 'x', 'current_step_id' => null, 'percent' => 1, 'started_at' => null, 'updated_at' => null, 'completed_at' => null, 'payload' => '']);
        $storage2 = new DoctrineDbalSetupProgressStorage($conn2);
        self::assertSame(SetupProgress::PHASE_IDLE, $storage2->load()->getPhase());
    }

    public function testDoctrineExecuteQueryOnlyConnection(): void
    {
        $conn = new class {
            /** @var array<string, mixed>|null */
            private ?array $row = null;

            /**
             * @param list<mixed> $params
             */
            public function executeQuery(string $sql, array $params = []): object
            {
                $normalized = strtolower($sql);
                if (str_contains($normalized, 'create table')) {
                    return new class {
                        public function fetchAssociative(): false
                        {
                            return false;
                        }
                    };
                }

                if (str_starts_with($normalized, 'insert')) {
                    $this->row = ['payload' => (string) ($params[8] ?? '')];

                    return new class {
                        public function fetchAssociative(): false
                        {
                            return false;
                        }
                    };
                }

                if (str_starts_with($normalized, 'update')) {
                    $this->row = ['payload' => (string) ($params[7] ?? '')];

                    return new class {
                        public function fetchAssociative(): false
                        {
                            return false;
                        }
                    };
                }

                $row = $this->row;

                return new class($row) {
                    /** @param array<string, mixed>|null $row */
                    public function __construct(private readonly ?array $row)
                    {
                    }

                    /** @return array<string, mixed>|false */
                    public function fetchAssociative(): array|false
                    {
                        return $this->row ?? false;
                    }
                };
            }
        };

        $storage = new DoctrineDbalSetupProgressStorage($conn);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'q', percent: 1.0));
        self::assertSame('q', $storage->load()->getCurrentStepId());
    }

    public function testDoctrineSavePersistFailure(): void
    {
        $conn = new class {
            public function executeQuery(string $sql): object
            {
                return new class {
                    public function fetchAssociative(): false
                    {
                        return false;
                    }
                };
            }

            /** @param list<mixed> $params */
            public function executeStatement(string $sql, array $params = []): never
            {
                throw new RuntimeException('write fail');
            }
        };
        $storage = new DoctrineDbalSetupProgressStorage($conn);
        $this->expectException(RuntimeException::class);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING));
    }

    public function testDoctrineCustomTableName(): void
    {
        $conn    = new FakeDbalConnection();
        $storage = new DoctrineDbalSetupProgressStorage($conn, 'custom_setup_progress');
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 't', percent: 2.0));
        self::assertSame('t', $storage->load()->getCurrentStepId());
    }

    public function testStepJournalUpsertsAndEnrichesCompletedIds(): void
    {
        $conn    = new FakeDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $storage = new DoctrineDbalSetupProgressStorage($conn, DoctrineDbalSetupProgressStorage::TABLE, $journal, true);

        $progress = new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'fresh_install',
            currentStepId: 'admin_user',
            percent: 80.0,
            completedStepIds: ['requirements', 'migrations'],
            updatedAt: new DateTimeImmutable('2026-08-15T10:00:00+00:00'),
            startedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
        );
        $storage->save($progress);

        self::assertNotEmpty($conn->stepRows());
        self::assertSame(
            ['requirements', 'migrations'],
            $journal->listCompletedStepIds('fresh_install'),
        );

        $latest = $journal->latestFinishedStep('fresh_install');
        // Running current step has no finished_at; latest finished is migrations.
        self::assertNotNull($latest);
        self::assertSame('migrations', $latest['step_id']);

        // Thin payload: enrich from journal rows.
        $thin     = new SetupProgress(phase: SetupProgress::PHASE_WAITING, profile: 'fresh_install', currentStepId: 'admin_user');
        $enriched = $journal->enrich($thin);
        self::assertSame(['requirements', 'migrations'], $enriched->getCompletedStepIds());
    }

    public function testStepJournalClearsOnIdleReset(): void
    {
        $conn    = new FakeDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $storage = new DoctrineDbalSetupProgressStorage($conn, DoctrineDbalSetupProgressStorage::TABLE, $journal, true);

        $storage->save(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            profile: 'fresh_install',
            currentStepId: 'migrations',
            completedStepIds: ['requirements'],
            startedAt: new DateTimeImmutable(),
        ));
        self::assertNotEmpty($conn->stepRows());

        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_IDLE, profile: 'fresh_install'));
        self::assertSame([], $conn->stepRows());
    }

    public function testStepJournalNoopWithoutConnection(): void
    {
        $journal = new DoctrineDbalSetupStepJournal();
        self::assertFalse($journal->isUsable());
        $journal->sync(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'x', completedStepIds: ['a']));
        self::assertSame([], $journal->listCompletedStepIds());
        self::assertNull($journal->latestFinishedStep());
    }
}
/**
 * Minimal DBAL-like connection for unit tests (no doctrine/dbal required).
 *
 * Supports the progress singleton table and the per-step journal table.
 */
final class FakeDbalConnection
{
    /** @var array<int, array<string, mixed>> */
    private array $progressRows = [];

    /** @var array<string, array<string, mixed>> keyed by profile\0step_id */
    private array $stepRows = [];

    private bool $progressTableReady = false;

    private bool $stepTableReady = false;

    /**
     * @param array<string, mixed> $row
     */
    public function seedRow(array $row): void
    {
        $this->progressTableReady = true;
        $this->progressRows[1]    = $row;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function stepRows(): array
    {
        return $this->stepRows;
    }

    /**
     * @param list<mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): int
    {
        $normalized = strtolower($sql);
        if (str_contains($normalized, 'create table')) {
            if (str_contains($normalized, 'step_order') || (str_contains($normalized, 'step_id') && !str_contains($normalized, 'current_step_id'))) {
                $this->stepTableReady = true;
            } else {
                $this->progressTableReady = true;
            }

            return 0;
        }

        if (str_contains($normalized, 'delete from') && (str_contains($normalized, 'setup_step') || str_contains($normalized, 'step_id') || str_contains($normalized, 'nowo_site_backup_setup_step') || $this->looksLikeStepTable($normalized))) {
            if (str_contains($normalized, 'where profile')) {
                $profile = (string) ($params[0] ?? '');
                foreach (array_keys($this->stepRows) as $key) {
                    if (str_starts_with($key, $profile . "\0")) {
                        unset($this->stepRows[$key]);
                    }
                }
            } else {
                $this->stepRows = [];
            }

            return 1;
        }

        if (str_contains($normalized, 'insert into') && $this->looksLikeStepTable($normalized)) {
            $profile                                   = (string) $params[0];
            $stepId                                    = (string) $params[1];
            $this->stepTableReady                      = true;
            $this->stepRows[$profile . "\0" . $stepId] = [
                'profile'     => $profile,
                'step_id'     => $stepId,
                'status'      => $params[2],
                'step_order'  => $params[3],
                'started_at'  => $params[4],
                'finished_at' => $params[5],
                'updated_at'  => $params[6],
                'message'     => $params[7],
            ];

            return 1;
        }

        if (str_starts_with(trim($normalized), 'update') && $this->looksLikeStepTable($normalized)) {
            $profile              = (string) $params[6];
            $stepId               = (string) $params[7];
            $key                  = $profile . "\0" . $stepId;
            $this->stepRows[$key] = [
                'profile'     => $profile,
                'step_id'     => $stepId,
                'status'      => $params[0],
                'step_order'  => $params[1],
                'started_at'  => $params[2],
                'finished_at' => $params[3],
                'updated_at'  => $params[4],
                'message'     => $params[5],
            ];

            return 1;
        }

        if (str_starts_with(trim($normalized), 'insert')) {
            $this->progressTableReady = true;
            $this->progressRows[1]    = [
                'phase'           => $params[1],
                'profile'         => $params[2],
                'current_step_id' => $params[3],
                'percent'         => $params[4],
                'started_at'      => $params[5],
                'updated_at'      => $params[6],
                'completed_at'    => $params[7],
                'payload'         => $params[8],
            ];

            return 1;
        }

        if (str_starts_with(trim($normalized), 'update')) {
            $this->progressRows[1] = [
                'phase'           => $params[0],
                'profile'         => $params[1],
                'current_step_id' => $params[2],
                'percent'         => $params[3],
                'started_at'      => $params[4],
                'updated_at'      => $params[5],
                'completed_at'    => $params[6],
                'payload'         => $params[7],
            ];

            return 1;
        }

        return 0;
    }

    /**
     * @param list<mixed> $params
     */
    public function executeQuery(string $sql, array $params = []): FakeDbalResult
    {
        $normalized = strtolower($sql);
        if (str_contains($normalized, 'create table')) {
            if (str_contains($normalized, 'step_order') || (str_contains($normalized, 'step_id') && !str_contains($normalized, 'current_step_id'))) {
                $this->stepTableReady = true;
            } else {
                $this->progressTableReady = true;
            }

            return new FakeDbalResult(null);
        }

        if ($this->looksLikeStepTable($normalized) && str_contains($normalized, 'select')) {
            if (!$this->stepTableReady) {
                return new FakeDbalResult(null, []);
            }

            if (str_contains($normalized, 'and step_id')) {
                $profile = (string) ($params[0] ?? '');
                $stepId  = (string) ($params[1] ?? '');
                $row     = $this->stepRows[$profile . "\0" . $stepId] ?? null;

                return new FakeDbalResult($row, $row !== null ? [$row] : []);
            }

            $rows = array_values($this->stepRows);
            if (str_contains($normalized, 'where profile') && isset($params[0])) {
                $profile = (string) $params[0];
                $rows    = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => ($row['profile'] ?? '') === $profile,
                ));
            }

            return new FakeDbalResult($rows[0] ?? null, $rows);
        }

        if (str_contains($normalized, 'select') && $this->progressTableReady && isset($this->progressRows[1])) {
            return new FakeDbalResult($this->progressRows[1]);
        }

        return new FakeDbalResult(null);
    }

    private function looksLikeStepTable(string $normalizedSql): bool
    {
        if (str_contains($normalizedSql, 'setup_step') || str_contains($normalizedSql, 'step_order')) {
            return true;
        }

        // Progress SQL uses current_step_id; journal uses bare step_id.
        return str_contains($normalizedSql, 'step_id') && !str_contains($normalizedSql, 'current_step_id');
    }
}

final class FakeDbalResult
{
    /**
     * @param array<string, mixed>|null $row
     * @param list<array<string, mixed>> $all
     */
    public function __construct(
        private readonly ?array $row,
        private readonly array $all = [],
    ) {
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchAssociative(): array|false
    {
        return $this->row ?? false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllAssociative(): array
    {
        if ($this->all !== []) {
            return $this->all;
        }

        return $this->row !== null ? [$this->row] : [];
    }
}
