<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\SetupStorage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\CacheDoctrineSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\CacheSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\ChainSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Process\Process;
use Throwable;

use function bin2hex;
use function class_exists;
use function extension_loaded;
use function getenv;
use function is_a;
use function is_string;
use function random_bytes;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * UT-2: the DBAL setup progress and step tables on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) and SQLite.
 *
 * Up to 1.36.x the runtime DDL used CLOB / DATETIME: MySQL / MariaDB failed with 1064 and PostgreSQL with
 * "type datetime does not exist", so the doctrine storage could not save and chain / cache_doctrine silently kept
 * the progress in the file / cache only.
 *
 * Servers come from SBB_DDL_MYSQL, SBB_DDL_MARIADB and SBB_DDL_PGSQL (DBAL URLs such as
 * `pdo-mysql://root:pw@127.0.0.1:3306/ddl`); SQLite runs whenever pdo_sqlite is loaded. Needs doctrine/dbal
 * (a soft dependency of the bundle: CI installs DBAL 3 and DBAL 4 for this job). Every test uses its own table names
 * and drops them afterwards; lock and statement timeouts are set so a stuck server fails fast.
 */
final class RealDatabaseSetupStorageTest extends TestCase
{
    /** @var list<array{mixed, string}> */
    private array $cleanup = [];

    private ?string $sqliteFile = null;

    private ?string $progressFile = null;

    private string $suffix = '';

    /** @var array<string, mixed> */
    private array $params = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as [$connection, $table]) {
            try {
                $connection->executeStatement('DROP TABLE ' . $table);
            } catch (Throwable) {
                // Never created.
            }
        }
        foreach ($this->cleanup as [$connection]) {
            $connection->close();
        }
        $this->cleanup = [];

        foreach ([$this->sqliteFile, $this->progressFile] as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        yield 'MySQL 8.4' => ['SBB_DDL_MYSQL'];
        yield 'MariaDB 11.4' => ['SBB_DDL_MARIADB'];
        yield 'PostgreSQL 16' => ['SBB_DDL_PGSQL'];
        yield 'SQLite' => ['sqlite'];
    }

    #[DataProvider('servers')]
    public function testProgressSaveLoadResetOnAFreshTable(string $server): void
    {
        [$connection, $progressTable, $stepTable] = $this->connect($server);
        $storage                                  = $this->progressStorage($connection, $progressTable, $stepTable);

        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $progress = new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            profile: 'default',
            currentStepId: 'migrations',
            percent: 42.5,
            message: str_repeat('long message ', 2000),
            completedStepIds: ['database_url', 'sql_file'],
            updatedAt: new DateTimeImmutable('2026-11-19 10:05:00'),
            startedAt: new DateTimeImmutable('2026-11-19 10:00:00'),
        );
        $storage->save($progress);

        // Another server / a fresh worker: new service instances, same database.
        $loaded = $this->progressStorage($connection, $progressTable, $stepTable)->load();
        self::assertSame(SetupProgress::PHASE_RUNNING, $loaded->getPhase());
        self::assertSame('migrations', $loaded->getCurrentStepId());
        self::assertSame(['database_url', 'sql_file'], $loaded->getCompletedStepIds());
        self::assertSame(42.5, $loaded->getPercent());
        self::assertSame($progress->getMessage(), $loaded->getMessage());

        $journal = new DoctrineDbalSetupStepJournal($connection, $stepTable);
        self::assertSame(['database_url', 'sql_file'], $journal->listCompletedStepIds('default'));
        // Date columns read back in the format the journal compares (DATETIME / TIMESTAMP(0)).
        $latest = $journal->latestFinishedStep('default');
        self::assertNotNull($latest);
        self::assertSame('sql_file', $latest['step_id']);
        self::assertSame('2026-11-19 10:05:00', $latest['finished_at']);

        // Reset (idle, empty): row back to idle and the profile's journal rows cleared.
        $storage->save(new SetupProgress(profile: 'default'));
        $reset = $this->progressStorage($connection, $progressTable, $stepTable)->load();
        self::assertSame(SetupProgress::PHASE_IDLE, $reset->getPhase());
        self::assertSame([], $reset->getCompletedStepIds());
        self::assertSame([], $journal->listCompletedStepIds('default'));

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $progressTable));
    }

    #[DataProvider('servers')]
    public function testTwoInstancesInsertThenUpdateTheSingletonRow(string $server): void
    {
        [$connection, $progressTable, $stepTable] = $this->connect($server);

        // Two processes start on an empty database: both run the DDL, the first inserts, the second updates.
        $first  = $this->progressStorage($connection, $progressTable, $stepTable);
        $second = $this->progressStorage($connection, $progressTable, $stepTable);
        self::assertSame(SetupProgress::PHASE_IDLE, $first->load()->getPhase());
        self::assertSame(SetupProgress::PHASE_IDLE, $second->load()->getPhase());

        $first->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'sql_file', completedStepIds: ['database_url']));
        $second->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations', completedStepIds: ['database_url', 'sql_file']));
        $first->save(new SetupProgress(phase: SetupProgress::PHASE_COMPLETED, completedStepIds: ['database_url', 'sql_file', 'migrations'], completedAt: new DateTimeImmutable('2026-11-19 11:00:00')));

        $loaded = $second->load();
        self::assertSame(SetupProgress::PHASE_COMPLETED, $loaded->getPhase());
        self::assertSame(['database_url', 'sql_file', 'migrations'], $loaded->getCompletedStepIds());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $progressTable));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $stepTable . " WHERE status = 'completed'"));

        // A second connection (another server) sees the same rows.
        [$other] = $this->connect($server, sameTables: true);
        self::assertSame(
            ['database_url', 'sql_file', 'migrations'],
            $this->progressStorage($other, $progressTable, $stepTable)->load()->getCompletedStepIds(),
        );
    }

    #[DataProvider('servers')]
    public function testConcurrentFirstUseCreatesTheTablesOnce(string $server): void
    {
        [$connection, $progressTable, $stepTable] = $this->connect($server);

        // Six workers hit an empty database at the same moment: every first load() runs the DDL of both tables.
        $startAt = microtime(true) + 2.0;
        $php     = PHP_BINARY;
        $child   = __DIR__ . '/Fixtures/ddl-child.php';
        $procs   = [];
        for ($i = 0; $i < 6; ++$i) {
            $process = new Process([$php, $child, json_encode($this->params, JSON_THROW_ON_ERROR), $progressTable, $stepTable, (string) $startAt]);
            $process->setTimeout(60);
            $process->start();
            $procs[] = $process;
        }

        $outputs = [];
        foreach ($procs as $process) {
            $process->wait();
            $outputs[] = trim($process->getOutput()) . trim($process->getErrorOutput());
        }

        self::assertSame(array_fill(0, 6, 'ok'), $outputs);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $progressTable));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $stepTable));
    }

    #[DataProvider('servers')]
    public function testChainKeepsCompletedStepsAfterTheFileIsWiped(string $server): void
    {
        [$connection, $progressTable, $stepTable] = $this->connect($server);
        $this->progressFile                       = sys_get_temp_dir() . '/sbddl-chain-' . bin2hex(random_bytes(4)) . '.json';
        $logger                                   = new RecordingLogger();
        $chain                                    = new ChainSetupProgressStorage(
            new FilesystemSetupProgressStorage($this->progressFile),
            $this->progressStorage($connection, $progressTable, $stepTable),
            $logger,
        );

        $chain->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, profile: 'default', currentStepId: 'seeds', completedStepIds: ['sql_file', 'migrations']));
        @unlink($this->progressFile);

        $loaded = $chain->load();
        self::assertSame(['sql_file', 'migrations'], $loaded->getCompletedStepIds(), 'completed steps must not re-run after a var/ wipe');
        self::assertSame([], $logger->records, 'the database layer works: no fallback warning');
    }

    #[DataProvider('servers')]
    public function testCacheDoctrineKeepsProgressAfterACacheFlush(string $server): void
    {
        [$connection, $progressTable, $stepTable] = $this->connect($server);
        $logger                                   = new RecordingLogger();
        $pool                                     = new ArrayAdapter();
        $storage                                  = new CacheDoctrineSetupProgressStorage(
            new CacheSetupProgressStorage($pool),
            $this->progressStorage($connection, $progressTable, $stepTable),
            null,
            $logger,
        );

        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'seeds', completedStepIds: ['sql_file']));
        $pool->clear();

        self::assertSame(['sql_file'], $storage->load()->getCompletedStepIds());
        self::assertSame([], $logger->records);
    }

    public function testSqliteTablesCreatedBefore137KeepWorking(): void
    {
        [$connection, $progressTable, $stepTable] = $this->connect('sqlite');

        // The 1.2-1.36 DDL, verbatim.
        $connection->executeStatement('CREATE TABLE ' . $progressTable . ' (
            id INTEGER NOT NULL PRIMARY KEY, phase VARCHAR(32) NOT NULL, profile VARCHAR(64) NOT NULL,
            current_step_id VARCHAR(128) DEFAULT NULL, percent DOUBLE PRECISION NOT NULL DEFAULT 0,
            started_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL,
            payload CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE ' . $stepTable . ' (
            profile VARCHAR(64) NOT NULL, step_id VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL,
            step_order INTEGER NOT NULL DEFAULT 0, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL, message CLOB DEFAULT NULL, PRIMARY KEY (profile, step_id))');
        $connection->executeStatement(
            'INSERT INTO ' . $progressTable . " (id, phase, profile, current_step_id, percent, payload) VALUES (1, 'running', 'default', 'migrations', 10, ?)",
            [json_encode((new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'migrations', completedStepIds: ['sql_file']))->toArray(), JSON_THROW_ON_ERROR)],
        );

        $storage = $this->progressStorage($connection, $progressTable, $stepTable);
        self::assertSame(['sql_file'], $storage->load()->getCompletedStepIds());
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'seeds', completedStepIds: ['sql_file', 'migrations']));
        self::assertSame(['sql_file', 'migrations'], $this->progressStorage($connection, $progressTable, $stepTable)->load()->getCompletedStepIds());
    }

    private function progressStorage(mixed $connection, string $progressTable, string $stepTable): DoctrineDbalSetupProgressStorage
    {
        return new DoctrineDbalSetupProgressStorage(
            $connection,
            $progressTable,
            new DoctrineDbalSetupStepJournal($connection, $stepTable),
        );
    }

    /**
     * DBAL class name built at run time: doctrine/dbal is a soft dependency, absent from the static analysis install.
     *
     * @return class-string
     */
    private function dbal(string $class): string
    {
        /** @var class-string $name */
        $name = 'Doctrine\\DBAL\\' . $class;

        return $name;
    }

    /**
     * @param class-string $class
     */
    private function make(string $class, mixed ...$arguments): mixed
    {
        return new $class(...$arguments);
    }

    /**
     * @return array{mixed, string, string}
     */
    private function connect(string $server, bool $sameTables = false): array
    {
        $driverManager = $this->dbal('DriverManager');
        $dsnParser     = $this->dbal('Tools\\DsnParser');
        if (!class_exists($driverManager) || !class_exists($dsnParser)) {
            self::markTestSkipped('doctrine/dbal >= 3.6 is not installed (soft dependency; the CI job installs it).');
        }

        if ($server === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                self::markTestSkipped('pdo_sqlite is not loaded.');
            }
            if (!$sameTables || $this->sqliteFile === null) {
                $this->sqliteFile = sys_get_temp_dir() . '/sbddl-' . bin2hex(random_bytes(4)) . '.sqlite';
            }
            $params = ['driver' => 'pdo_sqlite', 'path' => $this->sqliteFile];
        } else {
            $url = getenv($server);
            if (!is_string($url) || $url === '') {
                self::markTestSkipped($server . ' is not set.');
            }
            $parser = $this->make($dsnParser, ['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
            $params = $parser->parse($url);
        }

        $this->params = $params;
        /** @var mixed $connection */
        $connection = $driverManager::getConnection($params);
        $platform   = $connection->getDatabasePlatform();
        if (is_a($platform, $this->dbal('Platforms\\AbstractMySQLPlatform'))) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5, lock_wait_timeout = 5');
            $isMariaDb = str_contains((string) $connection->fetchOne('SELECT VERSION()'), 'MariaDB');
            $connection->executeStatement($isMariaDb ? 'SET SESSION max_statement_time = 30' : 'SET SESSION max_execution_time = 30000');
        } elseif (is_a($platform, $this->dbal('Platforms\\PostgreSQLPlatform'))) {
            $connection->executeStatement("SET lock_timeout = '5s'");
            $connection->executeStatement("SET statement_timeout = '30s'");
        } else {
            $connection->executeStatement('PRAGMA busy_timeout = 5000');
        }

        if (!$sameTables || $this->suffix === '') {
            $this->suffix = bin2hex(random_bytes(4));
        }
        $progressTable   = 'sbddl_progress_' . $this->suffix;
        $stepTable       = 'sbddl_step_' . $this->suffix;
        $this->cleanup[] = [$connection, $progressTable];
        $this->cleanup[] = [$connection, $stepTable];

        return [$connection, $progressTable, $stepTable];
    }
}
