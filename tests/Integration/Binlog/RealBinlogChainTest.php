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
use Nowo\SiteBackupBundle\Incremental\BinlogCapture;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisites;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\Check;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Incremental\MysqlClientBinlogServer;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
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
 * Items 9–11 against a real MySQL 9 server with binary logs and the Oracle client tools (CI job "Binlog incrementals
 * (MySQL 9)"): full → writes → incrementals → point-in-time restore, gap detection, and the password never reaching
 * argv nor the logs.
 *
 * Environment: SBB_BINLOG_DATABASE_URL (the backup account, with a password), SBB_BINLOG_ADMIN_URL (an account that may
 * PURGE BINARY LOGS), SBB_BINLOG_SHARED_DIR (mounted at the same path by the tool wrappers, which append their argv
 * to `<shared>/argv.log`), SBB_BINLOG_MYSQL / SBB_BINLOG_MYSQLDUMP / SBB_BINLOG_MYSQLBINLOG. Skipped without them.
 */
final class RealBinlogChainTest extends TestCase
{
    private string $url;

    private string $adminUrl;

    private string $shared;

    private string $mysql;

    private string $mysqldump;

    private string $mysqlbinlog;

    private string $project;

    protected function setUp(): void
    {
        $env = [];
        foreach (['SBB_BINLOG_DATABASE_URL', 'SBB_BINLOG_ADMIN_URL', 'SBB_BINLOG_SHARED_DIR', 'SBB_BINLOG_MYSQL', 'SBB_BINLOG_MYSQLDUMP', 'SBB_BINLOG_MYSQLBINLOG'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                self::markTestSkipped('Real binlog test: set ' . $name . ' (see the class doc).');
            }
            $env[$name] = $value;
        }
        $this->url         = $env['SBB_BINLOG_DATABASE_URL'];
        $this->adminUrl    = $env['SBB_BINLOG_ADMIN_URL'];
        $this->shared      = $env['SBB_BINLOG_SHARED_DIR'];
        $this->mysql       = $env['SBB_BINLOG_MYSQL'];
        $this->mysqldump   = $env['SBB_BINLOG_MYSQLDUMP'];
        $this->mysqlbinlog = $env['SBB_BINLOG_MYSQLBINLOG'];
        $this->project     = $this->shared . '/project-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$this->project . '/config', $this->shared . '/work']);
    }

    public function testFullWritesIncrementalsPointInTimeRestoreAndGaps(): void
    {
        $password = MysqlDumpCommandFactory::connection($this->url)['pass'];
        self::assertNotSame('', $password, 'the backup account needs a password for the argv / log assertions');

        $server = new MysqlClientBinlogServer($this->url, $this->mysql, 120, $this->shared . '/work');
        $checks = (new BinlogPrerequisites($server, ['mysqldump' => $this->mysqldump, 'mysql' => $this->mysql, 'mysqlbinlog' => $this->mysqlbinlog]))->check();
        self::assertTrue(Check::allPass($checks), (string) json_encode($checks));

        $tool     = new MysqlBinlogTool($this->mysqlbinlog, 300);
        $capture  = new BinlogCapture($server, $this->url, $tool, $this->shared . '/work');
        $archiver = new BackupArchiver(
            $this->project,
            $this->shared . '/storage',
            ['config'],
            [],
            null,
            300,
            builtinDumper: new BuiltinMysqlDumper($this->url, $this->mysqldump, timeoutSeconds: 300),
            workDir: $this->shared . '/work',
            binlogCapture: $capture,
            binlogServer: $server,
        );
        $logger  = new RecordingLogger();
        $history = new MemoryHistoryStorage();
        $manager = new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: $history,
            logger: $logger,
            chainRestorer: new ChainRestorer(
                $archiver,
                RestorePathGuard::fromArchiver($this->project, [], $archiver),
                $this->project,
                new DumpImporter($this->url, $this->mysql, timeoutSeconds: 300),
                new BinlogReplayer($this->url, $tool, $this->mysql, 300, $this->shared . '/work'),
            ),
        );

        $this->sql('DROP TABLE IF EXISTS pitr; CREATE TABLE pitr (id INT PRIMARY KEY, v VARBINARY(32)); INSERT INTO pitr VALUES (1, 0x61)');
        file_put_contents($this->project . '/config/app.yaml', "v: 1\n");

        $full = $manager->createBackup(new BackupOptions(recordBinlogCoordinates: true, recordFileManifest: true));
        $meta = $full->getMeta();
        self::assertMatchesRegularExpression('/^binlog\.\d+$/', (string) $meta['binlog_file']);
        self::assertSame(trim($this->sql('SELECT @@GLOBAL.server_uuid')), $meta['server_uuid']);

        // Writes, then a point in time T, then more writes.
        $this->sql('INSERT INTO pitr VALUES (2, 0x62); UPDATE pitr SET v = 0x00ff275c0d0a WHERE id = 1');
        file_put_contents($this->project . '/config/app.yaml', "v: 2\n");
        sleep(2);
        $until = new DateTimeImmutable(trim($this->sql('SELECT UTC_TIMESTAMP()')), new DateTimeZone('UTC'));
        sleep(2);
        $this->sql('INSERT INTO pitr VALUES (3, 0x63); DELETE FROM pitr WHERE id = 2');

        $i1 = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertNotSame([], $i1->getMeta()['binlog_files']);
        self::assertSame($meta['binlog_file'], $i1->getMeta()['binlog_from']['binlog_file']);

        sleep(1);
        $this->sql('INSERT INTO pitr VALUES (4, 0x64)');
        file_put_contents($this->project . '/config/app.yaml', "v: 3\n");
        $i2 = $manager->createBackup($manager->incrementalOptions($i1->getId()));

        // Point in time: rows as of T; files as of the last incremental taken before T (the full one).
        $this->sql('DELETE FROM pitr');
        $report = $manager->restoreChain($i2->getId(), $until);
        self::assertTrue($report->complete, (string) json_encode($report));
        self::assertSame("1\t00FF275C0D0A\n2\t62", trim($this->sql('SELECT id, HEX(v) FROM pitr ORDER BY id')));
        self::assertSame("v: 1\n", file_get_contents($this->project . '/config/app.yaml'));

        // The head: every event.
        $report = $manager->restoreChain($i2->getId());
        self::assertTrue($report->complete);
        self::assertSame("1\t00FF275C0D0A\n3\t63\n4\t64", trim($this->sql('SELECT id, HEX(v) FROM pitr ORDER BY id')));
        self::assertSame("v: 3\n", file_get_contents($this->project . '/config/app.yaml'));

        // Gaps: another server, then purged logs.
        try {
            $capture->capture(new BinlogCoordinates((string) $i2->getMeta()['binlog_file'], 4, '00000000-0000-0000-0000-000000000000'), 'app');
            self::fail('a capture from another server was accepted');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::SERVER_CHANGED, $e->getReason());
        }
        $this->sql('FLUSH BINARY LOGS', $this->adminUrl);
        $latest = $server->status()['file'];
        $this->sql("PURGE BINARY LOGS TO '" . $latest . "'", $this->adminUrl);
        try {
            $manager->createBackup($manager->incrementalOptions($i2->getId()));
            self::fail('a capture from purged logs was accepted');
        } catch (BinlogGapException $e) {
            self::assertSame(BinlogGapException::EXPIRED, $e->getReason());
        }

        // The password never reached a command line nor a log line.
        $argv = is_file($this->shared . '/argv.log') ? (string) file_get_contents($this->shared . '/argv.log') : '';
        self::assertStringContainsString('--defaults-file=', $argv);
        self::assertStringNotContainsString($password, $argv);
        self::assertStringNotContainsString($password, implode("\n", $logger->lines()));
        self::assertStringNotContainsString($password, (string) json_encode(array_map(static fn (BackupHistoryEntry $e): array => $e->toArray(), $history->list(500))));
        self::assertStringNotContainsString($password, (string) json_encode([$full->getMeta(), $i1->getMeta(), $i2->getMeta()]));
    }

    private function sql(string $statement, ?string $url = null): string
    {
        $dir = $this->shared . '/work/sql-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($dir, 0o700);
        try {
            $optionFile = MysqlOptionFile::write($url ?? $this->url, $dir);
            $database   = MysqlDumpCommandFactory::databaseName($url ?? $this->url);
            $process    = new Process([$this->mysql, '--defaults-file=' . $optionFile, '--batch', '--skip-column-names', '--execute=' . $statement, '--', $database]);
            $process->setTimeout(120);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
