<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\Binlog;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use Nowo\SiteBackupBundle\Database\MysqlOptionFile;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Incremental\BinlogCapture;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisites;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\ChainPaths;
use Nowo\SiteBackupBundle\Incremental\Check;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Incremental\MysqlClientBinlogServer;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Restore\RestorePathGuard;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryHistoryStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function array_map;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function implode;
use function is_file;
use function is_string;
use function json_encode;
use function random_bytes;
use function sleep;
use function trim;

/**
 * Items 9–11 and audit PP against a real MySQL 9 server with binary logs, a second application schema (a shared server,
 * the default assumption) and least-privilege accounts (CI job "Binlog incrementals (MySQL 9)").
 *
 * Environment (skipped without it):
 * - SBB_BINLOG_DATABASE_URL: the application's account (ALL on the schema only) = backup.database.url;
 * - SBB_BINLOG_BACKUP_URL: backup.database.binlog_url (RELOAD, REPLICATION CLIENT, REPLICATION SLAVE on *.*; read on
 *   the schema);
 * - SBB_BINLOG_RESTORE_URL: backup.database.restore_url (ALL on the schema, REPLICATION_APPLIER,
 *   SESSION_VARIABLES_ADMIN, SET_ANY_DEFINER for the app's views — no SYSTEM_VARIABLES_ADMIN, PP-M4);
 * - SBB_BINLOG_ADMIN_URL: an administrator (other schemas, accounts, DDL without a default database, PURGE);
 * - SBB_BINLOG_SHARED_DIR (mounted at the same path by the tool wrappers, which append their argv to
 *   `<shared>/argv.log`), SBB_BINLOG_MYSQL / SBB_BINLOG_MYSQLDUMP / SBB_BINLOG_MYSQLBINLOG.
 */
final class RealBinlogChainTest extends TestCase
{
    /** @var array<string, string> */
    private array $env = [];

    private string $project;

    private RecordingLogger $logger;

    private MemoryHistoryStorage $history;

    protected function setUp(): void
    {
        foreach (['SBB_BINLOG_DATABASE_URL', 'SBB_BINLOG_BACKUP_URL', 'SBB_BINLOG_RESTORE_URL', 'SBB_BINLOG_ADMIN_URL', 'SBB_BINLOG_SHARED_DIR', 'SBB_BINLOG_MYSQL', 'SBB_BINLOG_MYSQLDUMP', 'SBB_BINLOG_MYSQLBINLOG'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                self::markTestSkipped('Real binlog test: set ' . $name . ' (see the class doc).');
            }
            $this->env[$name] = $value;
        }
        $this->project = $this->env['SBB_BINLOG_SHARED_DIR'] . '/project-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$this->project . '/config', $this->work()]);
        $this->logger  = new RecordingLogger();
        $this->history = new MemoryHistoryStorage();

        $this->admin('DROP DATABASE IF EXISTS other; CREATE DATABASE other; CREATE TABLE other.victim (id INT PRIMARY KEY); INSERT INTO other.victim VALUES (1)');
        $this->admin('DROP DATABASE IF EXISTS app; CREATE DATABASE app');
    }

    public function testFilteredChainOnASharedServerWithDdlAfterTheFull(): void
    {
        $server = new MysqlClientBinlogServer($this->env['SBB_BINLOG_BACKUP_URL'], $this->env['SBB_BINLOG_MYSQL'], 120, $this->work());
        $checks = (new BinlogPrerequisites($server, ['mysqldump' => $this->env['SBB_BINLOG_MYSQLDUMP'], 'mysql' => $this->env['SBB_BINLOG_MYSQL'], 'mysqlbinlog' => $this->env['SBB_BINLOG_MYSQLBINLOG']], 604800, 30, $this->env['SBB_BINLOG_DATABASE_URL']))->check();
        self::assertTrue(Check::allPass($checks), (string) json_encode($checks));
        $byName = [];
        foreach ($checks as $check) {
            $byName[$check->name] = $check;
        }
        self::assertSame(Check::OK, $byName['grants']->status, 'the dedicated backup account holds no broad grant');
        // The backup account cannot see the other schemas (no SHOW DATABASES): least privilege, the check stays ok.
        self::assertSame(Check::OK, $byName['schemas']->status);

        [$manager] = $this->stack(IncrementalArtifact::FILTERED);

        $this->app('CREATE TABLE pitr (id INT PRIMARY KEY, v VARBINARY(32)); INSERT INTO pitr VALUES (1, 0x61)');
        file_put_contents($this->project . '/config/app.yaml', "v: 1\n");
        $full = $manager->createBackup(new BackupOptions(recordBinlogCoordinates: true, recordFileManifest: true));
        self::assertSame(trim($this->admin('SELECT @@GLOBAL.server_uuid')), $full->getMeta()['server_uuid']);
        sleep(1);

        // Writes on the shared server: another tenant (rows, an account), PP-M2 scenario B (another schema's table
        // dropped from an app session) and PP-M1 scenario C (DDL on app without a default database).
        $this->app('INSERT INTO pitr VALUES (2, 0x62); UPDATE pitr SET v = 0x00ff275c0d0a WHERE id = 1');
        $this->admin('INSERT INTO other.victim VALUES (2)');
        $this->admin("CREATE USER IF NOT EXISTS 'tenant'@'%' IDENTIFIED BY 'tenant-REDACTED-TEST'");
        $this->admin('DROP TABLE other.victim; CREATE TABLE other.victim (id INT PRIMARY KEY); INSERT INTO other.victim VALUES (42)', 'app');
        $this->admin('ALTER TABLE app.pitr ADD COLUMN w INT NOT NULL DEFAULT 7');
        file_put_contents($this->project . '/config/app.yaml', "v: 2\n");
        sleep(2);
        $until = new DateTimeImmutable(trim($this->admin('SELECT UTC_TIMESTAMP()')), new DateTimeZone('UTC'));
        sleep(2);
        $this->app('INSERT INTO pitr VALUES (3, 0x63, 33); DELETE FROM pitr WHERE id = 2');

        $i1 = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertSame(IncrementalArtifact::FILTERED, $i1->getMeta()['binlog_capture']);
        self::assertGreaterThan(0, $i1->getMeta()['binlog_events']['dropped_events']);
        // PP-M3: the archive holds the app schema's events only.
        $events = $this->extract($i1, ChainPaths::EVENTS);
        self::assertStringNotContainsString('victim', $events);
        self::assertStringNotContainsString('tenant', $events);
        self::assertStringNotContainsString('`other`', $events);
        self::assertStringContainsString('ALTER TABLE app.pitr ADD COLUMN w', $events);

        // PP-H1 scenario A: objects created after the full backup.
        sleep(1);
        $this->app('CREATE TABLE newt (id INT PRIMARY KEY); INSERT INTO newt VALUES (1); CREATE VIEW v_pitr AS SELECT id FROM pitr; INSERT INTO pitr VALUES (4, 0x64, 44)');
        file_put_contents($this->project . '/config/app.yaml', "v: 3\n");
        $i2 = $manager->createBackup($manager->incrementalOptions($i1->getId()));

        $before = $this->app('SELECT COUNT(*) FROM pitr');
        try {
            $manager->restoreChain($i2->getId());
            self::fail('a restore onto a schema with newer objects was attempted');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('TABLE newt', $e->getMessage());
            self::assertStringContainsString('VIEW v_pitr', $e->getMessage());
        }
        self::assertSame($before, $this->app('SELECT COUNT(*) FROM pitr'), 'the refused restore changed the database');

        // Point in time T, with --recreate-database: rows and columns as of T, files as of the full backup.
        $report = $manager->restoreChain($i2->getId(), $until, 'ci', null, true);
        self::assertTrue($report->complete, (string) json_encode($report));
        self::assertSame("1\t00FF275C0D0A\t7\n2\t62\t7", trim($this->app('SELECT id, HEX(v), w FROM pitr ORDER BY id')));
        self::assertSame('', trim($this->app("SHOW TABLES LIKE 'newt'")));
        self::assertSame("v: 1\n", file_get_contents($this->project . '/config/app.yaml'));
        self::assertEquals($until, $report->databaseAt);

        // The head: every event, the new objects included.
        $report = $manager->restoreChain($i2->getId(), null, 'ci', null, true);
        self::assertTrue($report->complete);
        self::assertSame("1\t00FF275C0D0A\t7\n3\t63\t33\n4\t64\t44", trim($this->app('SELECT id, HEX(v), w FROM pitr ORDER BY id')));
        self::assertSame('1', trim($this->app('SELECT COUNT(*) FROM newt')));
        self::assertSame('3', trim($this->app('SELECT COUNT(*) FROM v_pitr')));
        self::assertSame("v: 3\n", file_get_contents($this->project . '/config/app.yaml'));

        // The other tenant was never touched by any restore.
        self::assertSame('42', trim($this->admin('SELECT GROUP_CONCAT(id) FROM other.victim')));

        // Gaps: another server, then purged logs.
        $capture = new BinlogCapture($server, $this->env['SBB_BINLOG_BACKUP_URL'], new MysqlBinlogTool($this->env['SBB_BINLOG_MYSQLBINLOG'], 300), $this->work());
        try {
            $capture->capture(new BinlogCoordinates((string) $i2->getMeta()['binlog_file'], 4, '00000000-0000-0000-0000-000000000000'), 'app');
            self::fail('a capture from another server was accepted');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::SERVER_CHANGED, $e->getReason());
        }
        $this->admin('FLUSH BINARY LOGS');
        $this->admin("PURGE BINARY LOGS TO '" . $server->status()['file'] . "'");
        try {
            $manager->createBackup($manager->incrementalOptions($i2->getId()));
            self::fail('a capture from purged logs was accepted');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::EXPIRED, $e->getReason());
        }

        $this->assertNoPassword([$full, $i1, $i2]);
    }

    public function testRawCapturesAreRefusedAtRestoreWhenTheyTouchAnotherSchema(): void
    {
        [$manager] = $this->stack(IncrementalArtifact::RAW);
        $this->app('CREATE TABLE pitr (id INT PRIMARY KEY, v INT); INSERT INTO pitr VALUES (1, 1)');
        $full = $manager->createBackup(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));

        // Scenario B: from an app session, another schema's table is dropped and re-created by its tenant.
        $this->app('INSERT INTO pitr VALUES (2, 2)');
        $this->admin('DROP TABLE other.victim', 'app');
        $this->admin('CREATE TABLE other.victim (id INT PRIMARY KEY); INSERT INTO other.victim VALUES (42)');
        $inc = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertSame(IncrementalArtifact::RAW, $inc->getMeta()['binlog_capture']);

        try {
            $manager->restoreChain($inc->getId(), null, 'ci', null, true);
            self::fail('a raw replay touching another schema was attempted');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('outside schema app', $e->getMessage());
        }
        self::assertSame('42', trim($this->admin('SELECT GROUP_CONCAT(id) FROM other.victim')));
        self::assertSame('2', trim($this->app('SELECT COUNT(*) FROM pitr')), 'the refused restore changed the database');
        $this->assertNoPassword([$full, $inc]);
    }

    /**
     * @return array{0: SiteBackupManager, 1: BackupArchiver}
     */
    private function stack(string $mode): array
    {
        $work     = $this->work();
        $mysql    = $this->env['SBB_BINLOG_MYSQL'];
        $tool     = new MysqlBinlogTool($this->env['SBB_BINLOG_MYSQLBINLOG'], 300);
        $server   = new MysqlClientBinlogServer($this->env['SBB_BINLOG_BACKUP_URL'], $mysql, 120, $work);
        $archiver = new BackupArchiver(
            $this->project,
            $this->env['SBB_BINLOG_SHARED_DIR'] . '/storage-' . $mode . '-' . bin2hex(random_bytes(3)),
            ['config'],
            [],
            null,
            300,
            builtinDumper: new BuiltinMysqlDumper($this->env['SBB_BINLOG_DATABASE_URL'], $this->env['SBB_BINLOG_MYSQLDUMP'], timeoutSeconds: 300, binlogDatabaseUrl: $this->env['SBB_BINLOG_BACKUP_URL']),
            workDir: $work,
            binlogCapture: new BinlogCapture($server, $this->env['SBB_BINLOG_BACKUP_URL'], $tool, $work, mode: $mode),
            binlogServer: $server,
        );
        $manager = new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: $this->history,
            logger: $this->logger,
            chainRestorer: new ChainRestorer(
                $archiver,
                RestorePathGuard::fromArchiver($this->project, [], $archiver),
                $this->project,
                new DumpImporter($this->env['SBB_BINLOG_RESTORE_URL'], $mysql, timeoutSeconds: 300),
                new BinlogReplayer($this->env['SBB_BINLOG_RESTORE_URL'], $tool, $mysql, 300, $work),
            ),
        );

        return [$manager, $archiver];
    }

    /**
     * @param list<BackupArtifact> $artifacts
     */
    private function assertNoPassword(array $artifacts): void
    {
        $argv = is_file($this->env['SBB_BINLOG_SHARED_DIR'] . '/argv.log') ? (string) file_get_contents($this->env['SBB_BINLOG_SHARED_DIR'] . '/argv.log') : '';
        self::assertStringContainsString('--defaults-file=', $argv);
        foreach (['SBB_BINLOG_DATABASE_URL', 'SBB_BINLOG_BACKUP_URL', 'SBB_BINLOG_RESTORE_URL'] as $name) {
            $password = MysqlDumpCommandFactory::connection($this->env[$name])['pass'];
            self::assertNotSame('', $password, $name . ' needs a password for this assertion');
            self::assertStringNotContainsString($password, $argv, $name);
            self::assertStringNotContainsString($password, implode("\n", $this->logger->lines()), $name);
            self::assertStringNotContainsString($password, (string) json_encode(array_map(static fn ($e): array => $e->toArray(), $this->history->list(500))), $name);
            self::assertStringNotContainsString($password, (string) json_encode(array_map(static fn (BackupArtifact $a): array => $a->getMeta(), $artifacts)), $name);
        }
    }

    private function extract(BackupArtifact $artifact, string $relative): string
    {
        $dir = $this->work() . '/extract-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        try {
            (new Process(['tar', '-xzf', $artifact->getAbsolutePath(), '-C', $dir]))->mustRun();

            return (string) file_get_contents($dir . '/' . $relative);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    private function work(): string
    {
        return $this->env['SBB_BINLOG_SHARED_DIR'] . '/work';
    }

    private function app(string $sql): string
    {
        return $this->sql($this->env['SBB_BINLOG_DATABASE_URL'], $sql, 'app');
    }

    private function admin(string $sql, ?string $database = null): string
    {
        return $this->sql($this->env['SBB_BINLOG_ADMIN_URL'], $sql, $database);
    }

    private function sql(string $url, string $statement, ?string $database): string
    {
        $dir = $this->work() . '/sql-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($dir, 0o700);
        try {
            $optionFile = MysqlOptionFile::write($url, $dir);
            $command    = [$this->env['SBB_BINLOG_MYSQL'], '--defaults-file=' . $optionFile, '--batch', '--skip-column-names', '--execute=' . $statement];
            if ($database !== null) {
                $command = [...$command, '--', $database];
            }
            $process = new Process($command);
            $process->setTimeout(120);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
