<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Concurrency;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Process\Process;
use Throwable;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function glob;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function str_starts_with;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * REQ-RUNTIME-003: real races between separate PHP processes on a shared lock store. Only one backup / restore /
 * setup run holds the operation lock; the other gets {@see BackupAlreadyRunningException} and does nothing.
 *
 * Stores: the flock fallback (one host), Symfony Lock FlockStore (shared directory) and, when
 * SBB_RACE_MYSQL / SBB_RACE_MARIADB / SBB_RACE_PGSQL are set ("dsn|user|password"), Symfony Lock PdoStore on that
 * server (CI job "Databases (lock-store races)": MySQL 8.4, MariaDB 11.4, PostgreSQL 16). Every child process has a
 * hard timeout, so a hang fails fast.
 */
final class OperationLockRaceTest extends TestCase
{
    private const CHILD = __DIR__ . '/Fixtures/race-child.php';

    private const CHILD_TIMEOUT = 60.0;

    public const PDO_TABLE = 'sbb_race_lock_keys';

    private string $key;

    private string $root;

    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs   = new Filesystem();
        $this->root = sys_get_temp_dir() . '/nowo-sbb-race-' . uniqid('', true);
        // One key per test: a row left by a killed holder never leaks into the next test.
        $this->key = 'sbb_race.' . bin2hex(random_bytes(6));
        $this->fs->mkdir([$this->root . '/project/config', $this->root . '/storage', $this->root . '/locks', $this->root . '/setup']);
        file_put_contents($this->root . '/project/config/app.yaml', "foo: bar\n");
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->root);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'flock fallback' => ['flock'];
        yield 'symfony FlockStore' => ['flock_store'];
        foreach (['mysql' => 'SBB_RACE_MYSQL', 'mariadb' => 'SBB_RACE_MARIADB', 'pgsql' => 'SBB_RACE_PGSQL'] as $name => $env) {
            yield 'symfony PdoStore ' . $name => ['pdo:' . $env];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expiringStores(): iterable
    {
        foreach (['mysql' => 'SBB_RACE_MYSQL', 'mariadb' => 'SBB_RACE_MARIADB', 'pgsql' => 'SBB_RACE_PGSQL'] as $name => $env) {
            yield 'symfony PdoStore ' . $name => ['pdo:' . $env];
        }
    }

    #[DataProvider('stores')]
    public function testTwoBackupsAtOnceOnlyOneRuns(string $store): void
    {
        $results = $this->race($store, [['op' => 'create'], ['op' => 'create']]);

        self::assertSame(['locked' => 1, 'won' => 1], $this->outcomes($results), $this->describe($results));
        self::assertCount(1, $this->archives(), 'exactly one backup was written');
        $this->assertLockFree($store);
    }

    #[DataProvider('stores')]
    public function testARestoreNeverRunsWhileABackupIsTaken(string $store): void
    {
        $id      = $this->seedBackup();
        $results = $this->race($store, [['op' => 'create'], ['op' => 'restore', 'backup_id' => $id, 'delay' => 0.5]]);

        self::assertSame('won', $results[0]['outcome'], $this->describe($results));
        self::assertSame('locked', $results[1]['outcome'], $this->describe($results));
        self::assertSame([], $this->history('restore_start'), 'the refused restore wrote nothing');
        $this->assertLockFree($store);
    }

    #[DataProvider('stores')]
    public function testTwoRestoresAtOnceOnlyOneRuns(string $store): void
    {
        $id      = $this->seedBackup();
        $results = $this->race($store, [['op' => 'restore', 'backup_id' => $id], ['op' => 'restore', 'backup_id' => $id]]);

        self::assertSame(['locked' => 1, 'won' => 1], $this->outcomes($results), $this->describe($results));
        self::assertCount(1, $this->history('restore_start'));
        self::assertCount(1, $this->history('restore_complete'));
        $this->assertLockFree($store);
    }

    #[DataProvider('stores')]
    public function testABackupNeverStartsWhileARestoreRuns(string $store): void
    {
        $id      = $this->seedBackup();
        $results = $this->race($store, [['op' => 'restore', 'backup_id' => $id], ['op' => 'create', 'delay' => 0.5]]);

        self::assertSame('won', $results[0]['outcome'], $this->describe($results));
        self::assertSame('locked', $results[1]['outcome'], $this->describe($results));
        self::assertCount(1, $this->archives(), 'only the seeded backup exists: nothing was taken mid-restore');
    }

    #[DataProvider('stores')]
    public function testClearingTheRestoreStatusWaitsForTheRunningRestore(string $store): void
    {
        $id      = $this->seedBackup();
        $results = $this->race($store, [['op' => 'restore', 'backup_id' => $id], ['op' => 'clear_restore', 'delay' => 0.5]]);

        self::assertSame('won', $results[0]['outcome'], $this->describe($results));
        self::assertSame('locked', $results[1]['outcome'], $this->describe($results));
    }

    #[DataProvider('stores')]
    public function testTwoSetupRunsAtOnceRunTheStepOnce(string $store): void
    {
        $results = $this->race($store, [['op' => 'setup'], ['op' => 'setup']]);

        self::assertSame(['setup_locked' => 1, 'won' => 1], $this->outcomes($results), $this->describe($results));
        $log = (string) @file_get_contents($this->root . '/setup/steps.log');
        self::assertSame(1, substr_count($log, ' start'), $log);
        self::assertSame(1, substr_count($log, ' end'), $log);
    }

    /**
     * A holder that dies (worker OOM, SIGKILL) never blocks the next operation: flock is freed by the kernel, a
     * FlockStore likewise.
     */
    #[DataProvider('stores')]
    public function testAKilledHolderFreesTheLock(string $store): void
    {
        $job = $this->job($store, ['op' => 'hold', 'ttl' => 2.0], 0);
        touch($this->root . '/barrier');
        $process = new Process([PHP_BINARY, self::CHILD, $job]);
        $process->setTimeout(self::CHILD_TIMEOUT);
        $process->start();
        $deadline = microtime(true) + 20;
        while (!is_file($this->root . '/result-0.json.held') && microtime(true) < $deadline && $process->isRunning()) {
            usleep(20_000);
        }
        self::assertFileExists($this->root . '/result-0.json.held', $process->getErrorOutput() . $process->getOutput());

        try {
            $this->lock($store, 2.0)->acquire();
            self::fail('The lock must be held while the holder lives.');
        } catch (BackupAlreadyRunningException) {
        }

        $process->signal(9);
        $process->wait();
        if (str_starts_with($store, 'pdo:')) {
            // A database store row outlives the process: it is free once its TTL passed.
            usleep(2_500_000);
        }

        $this->lock($store, 2.0)->acquire()->release();
        $this->addToAssertionCount(1);
    }

    /**
     * A TTL shorter than the backup is kept alive by the heartbeat refresh: a racer that arrives after the TTL is
     * still refused.
     */
    #[DataProvider('expiringStores')]
    public function testTheHeartbeatKeepsAShortTtlAliveDuringALongBackup(string $store): void
    {
        $results = $this->race($store, [['op' => 'create', 'ttl' => 3.0, 'dump_seconds' => 6], ['op' => 'create', 'ttl' => 3.0, 'delay' => 4.0]]);

        self::assertSame('won', $results[0]['outcome'], $this->describe($results));
        self::assertSame('locked', $results[1]['outcome'], $this->describe($results));
        self::assertCount(1, $this->archives());
    }

    /**
     * @param list<array<string, mixed>> $jobs
     *
     * @return list<array{outcome: string, start: float, end: float, id: ?string, error: ?string}>
     */
    private function race(string $store, array $jobs): array
    {
        $processes = [];
        foreach ($jobs as $i => $overrides) {
            $process = new Process([PHP_BINARY, self::CHILD, $this->job($store, $overrides, $i)]);
            $process->setTimeout(self::CHILD_TIMEOUT);
            $process->start();
            $processes[] = $process;
        }
        // Let both load the autoloader and build their services, then release them together.
        usleep(800_000);
        touch($this->root . '/barrier');

        $results = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            $file = $this->root . '/result-' . $i . '.json';
            self::assertFileExists($file, 'racer ' . $i . ' died: ' . $process->getErrorOutput() . $process->getOutput());
            /** @var array{outcome: string, start: float, end: float, id: ?string, error: ?string} $result */
            $result    = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $results[] = $result;
        }

        foreach ($results as $result) {
            self::assertNotSame('error', $result['outcome'], (string) $result['error']);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function job(string $store, array $overrides, int $index): string
    {
        $job = [
            'store'        => str_starts_with($store, 'pdo:') ? 'pdo' : $store,
            'key'          => $this->key,
            'lock_dir'     => $this->root . '/locks',
            'ttl'          => 60.0,
            'project_dir'  => $this->root . '/project',
            'storage_dir'  => $this->root . '/storage',
            'history'      => $this->root . '/history.jsonl',
            'progress'     => $this->root . '/restore-progress.json',
            'barrier'      => $this->root . '/barrier',
            'result'       => $this->root . '/result-' . $index . '.json',
            'dump_seconds' => 2,
            'delay'        => 0.0,
            'setup_dir'    => $this->root . '/setup',
            'setup_log'    => $this->root . '/setup/steps.log',
        ] + $this->pdoJob($store) + [];
        $job  = $overrides + $job;
        $file = $this->root . '/job-' . $index . '.json';
        file_put_contents($file, json_encode($job, JSON_THROW_ON_ERROR));

        return $file;
    }

    /**
     * @return array{pdo_dsn?: string, pdo_user?: string, pdo_password?: string}
     */
    private function pdoJob(string $store): array
    {
        if (!str_starts_with($store, 'pdo:')) {
            return [];
        }
        $value = getenv(substr($store, 4));
        if (!is_string($value) || $value === '') {
            self::markTestSkipped(substr($store, 4) . ' is not set (CI job "Databases (lock-store races)").');
        }
        $parts = explode('|', $value, 3);
        $job   = ['pdo_dsn' => $parts[0], 'pdo_user' => $parts[1] ?? '', 'pdo_password' => $parts[2] ?? ''];
        try {
            (new PdoStore(self::pdo($job), ['db_table' => self::PDO_TABLE]))->createTable();
        } catch (Throwable) {
            // Already created by an earlier test.
        }

        return $job;
    }

    private function lock(string $store, float $ttl): BackupOperationLock
    {
        $factory = match (true) {
            $store === 'flock'       => null,
            $store === 'flock_store' => new LockFactory(new FlockStore($this->root . '/locks')),
            default                  => new LockFactory(new PdoStore(self::pdo($this->pdoJob($store)), ['db_table' => self::PDO_TABLE])),
        };

        return new BackupOperationLock($this->root . '/locks', $factory, $this->key, $ttl);
    }

    /**
     * @param array{pdo_dsn?: string, pdo_user?: string, pdo_password?: string} $job
     */
    public static function pdo(array $job): PDO
    {
        $pdo = new PDO((string) ($job['pdo_dsn'] ?? ''), $job['pdo_user'] ?? null, $job['pdo_password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // A hang fails fast instead of holding the machine.
        $pdo->exec(str_starts_with((string) ($job['pdo_dsn'] ?? ''), 'pgsql:') ? "SET lock_timeout = '5s'" : 'SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }

    private function assertLockFree(string $store): void
    {
        $this->lock($store, 60.0)->acquire()->release();
        $this->addToAssertionCount(1);
    }

    private function seedBackup(): string
    {
        $archiver = new BackupArchiver(
            projectDir: $this->root . '/project',
            storageDir: $this->root . '/storage',
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: "echo '-- seed dump'",
            processTimeoutSeconds: 60,
        );

        return $archiver->create('seed')->getId();
    }

    /**
     * @return list<string>
     */
    private function archives(): array
    {
        return array_values(array_filter(glob($this->root . '/storage/*') ?: [], static fn (string $f): bool => !str_contains($f, '/.') && (str_ends_with($f, '.tar.gz') || str_ends_with($f, '.zip') || str_ends_with($f, '.sql.gz'))));
    }

    /**
     * @return list<string>
     */
    private function history(string $action): array
    {
        $rows = (new FilesystemBackupHistoryStorage($this->root . '/history.jsonl'))->list(100);

        return array_values(array_map(static fn (BackupHistoryEntry $r): string => $r->getAction(), array_filter($rows, static fn (BackupHistoryEntry $r): bool => $r->getAction() === $action)));
    }

    /**
     * @param list<array{outcome: string}> $results
     *
     * @return array<string, int>
     */
    private function outcomes(array $results): array
    {
        $counts = array_count_values(array_column($results, 'outcome'));
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function describe(array $results): string
    {
        try {
            return json_encode($results, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return (string) count($results);
        }
    }
}
