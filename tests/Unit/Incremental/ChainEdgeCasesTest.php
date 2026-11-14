<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\BinlogCapture;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogEventTimes;
use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisites;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\ChainPaths;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Incremental\MysqlClientBinlogServer;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Restore\RestorePathGuard;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryHistoryStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryRestoreProgressStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function array_map;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function hash;
use function hash_file;
use function json_decode;
use function json_encode;
use function scandir;
use function symlink;
use function touch;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Chains that must fail closed: tampered metadata or archives, unusable tools, guarded paths.
 */
final class ChainEdgeCasesTest extends TestCase
{
    private string $root;

    private string $project;

    private string $storage;

    private string $tools;

    private MockClock $clock;

    private int $tick = 0;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-chain-edge-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        $this->tools   = $this->root . '/tools';
        (new Filesystem())->mkdir([$this->project . '/config', $this->root . '/work', $this->tools, $this->root . '/server']);
        $this->clock = new MockClock('2026-11-01 00:00:00 UTC');
        $this->put('config/app.yaml', 'a');
        $this->put('config/old.yaml', 'old');
    }

    protected function tearDown(): void
    {
        @chmod($this->project . '/config/sub', 0o755);
        (new Filesystem())->remove($this->root);
    }

    public function testTamperedChainMetadataAndArchivesFailClosed(): void
    {
        [$manager, $archiver] = $this->filesManager();
        $full                 = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $this->clock->sleep(60);
        $this->put('config/app.yaml', 'b');
        (new Filesystem())->remove($this->project . '/config/old.yaml');
        $inc = $manager->createBackup($manager->incrementalOptions($full->getId()));

        $cases = [
            'no DELETED.json' => [static function (string $dir, array &$sums): void {
                unset($sums[ChainPaths::DELETED]);
            }, 'has no DELETED.json'],
            'DELETED.json not json' => [static function (string $dir, array &$sums): void {
                file_put_contents($dir . '/' . ChainPaths::DELETED, '{nope');
                $sums[ChainPaths::DELETED] = (string) hash_file('sha256', $dir . '/' . ChainPaths::DELETED);
            }, 'not a list of paths'],
            'DELETED.json unsafe path' => [static function (string $dir, array &$sums): void {
                file_put_contents($dir . '/' . ChainPaths::DELETED, '["../../etc/passwd"]');
                $sums[ChainPaths::DELETED] = (string) hash_file('sha256', $dir . '/' . ChainPaths::DELETED);
            }, 'unsafe path'],
            'DELETED.json not strings' => [static function (string $dir, array &$sums): void {
                file_put_contents($dir . '/' . ChainPaths::DELETED, '[1]');
                $sums[ChainPaths::DELETED] = (string) hash_file('sha256', $dir . '/' . ChainPaths::DELETED);
            }, 'unsafe path'],
            'file not in manifest' => [static function (string $dir, array &$sums): void {
                file_put_contents($dir . '/config/extra.yaml', 'x');
                $sums['config/extra.yaml'] = (string) hash_file('sha256', $dir . '/config/extra.yaml');
            }, 'not in the manifest'],
            'file differs' => [static function (string $dir, array &$sums): void {
                file_put_contents($dir . '/config/app.yaml', 'evil');
                $sums['config/app.yaml'] = (string) hash_file('sha256', $dir . '/config/app.yaml');
            }, 'does not match the manifest'],
        ];
        $original = $this->snapshot($inc);
        foreach ($cases as $case => [$mutate, $expected]) {
            $this->restoreSnapshot($inc, $original);
            $this->rewrite($archiver, $inc, $mutate);
            $this->put('config/app.yaml', 'live');
            try {
                $manager->restoreChain($inc->getId());
                self::fail($case . ': restored');
            } catch (ChainBrokenException $e) {
                self::assertStringContainsString($expected, $e->getMessage(), $case);
            }
            self::assertSame('live', (string) file_get_contents($this->project . '/config/app.yaml'), $case . ': something was applied');
        }
    }

    public function testGuardedDeletionsAndAFailedDeletionAreReported(): void
    {
        [$manager, $archiver] = $this->filesManager();
        $this->put('config/sub/gone.yaml', 'gone');
        $full = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $this->clock->sleep(60);
        (new Filesystem())->remove($this->project . '/config/sub/gone.yaml');
        $this->put('config/new.yaml', 'n');
        $inc = $manager->createBackup($manager->incrementalOptions($full->getId()));

        // Protected: never deleted; a directory in the way: reported.
        $guarded = new ChainRestorer($archiver, new RestorePathGuard($this->project, ['config/sub'], [], new SecretPathPolicy()), $this->project);
        $this->put('config/sub/gone.yaml', 'kept');
        $report = $guarded->restore($manager->resolveChain($inc->getId()));
        self::assertStringContainsString('config/sub/gone.yaml (protected path)', $report->steps[1]->detail);
        self::assertFileExists($this->project . '/config/sub/gone.yaml');

        (new Filesystem())->remove($this->project . '/config/sub/gone.yaml');
        (new Filesystem())->mkdir($this->project . '/config/sub/gone.yaml');
        $report = $manager->restoreChain($inc->getId());
        self::assertStringContainsString('config/sub/gone.yaml (a directory)', $report->steps[1]->detail);
        (new Filesystem())->remove($this->project . '/config/sub/gone.yaml');

        // A deletion that fails after the files were written: the report says so.
        $this->put('config/sub/gone.yaml', 'stuck');
        chmod($this->project . '/config/sub', 0o555);
        try {
            $manager->restoreChain($inc->getId());
            self::fail('a failed deletion was not reported');
        } catch (ChainRestoreException $e) {
            self::assertSame(['files:applied', 'deletions:failed'], array_map(static fn (ChainRestoreStep $s): string => $s->part . ':' . $s->status, $e->getReport()->steps));
            self::assertStringContainsString('Unable to delete config/sub/gone.yaml', (string) $e->getReport()->error);
        } finally {
            chmod($this->project . '/config/sub', 0o755);
        }
    }

    public function testDatabaseOnlyChainsAndPointsInTime(): void
    {
        [$manager, $archiver] = $this->databaseManager();
        // Files are archived but not tracked: the chain restores them as of the full backup.
        $full = $manager->createBackup(new BackupOptions(recordBinlogCoordinates: true));
        $this->clock->sleep(3600);
        $i1 = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertFalse($i1->getMeta()['content'] === 'files');

        $report = $manager->restoreChain($i1->getId(), new DateTimeImmutable('2026-11-01 00:05:00 UTC'));
        self::assertSame(['database_dump:applied', 'binlog:skipped', 'files:applied'], array_map(static fn (ChainRestoreStep $s): string => $s->part . ':' . $s->status, $report->steps));
        self::assertStringContainsString('no file deltas', implode(' ', $report->notes));

        $report = $manager->restoreChain($i1->getId(), new DateTimeImmutable('2027-01-01 UTC'));
        self::assertStringContainsString('Nothing was captured after', implode(' ', $report->notes));

        // Database only (no files at all); a new chain taken after the restores (same-second fulls count as closed).
        $this->clock->sleep(1);
        $dbOnly = $manager->createBackup(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));
        $this->clock->sleep(3600);
        $dbInc  = $manager->createBackup($manager->incrementalOptions($dbOnly->getId()));
        $report = $manager->restoreChain($dbInc->getId());
        self::assertSame(['database_dump:applied', 'binlog:applied'], array_map(static fn (ChainRestoreStep $s): string => $s->part . ':' . $s->status, $report->steps));

        // Without an importer, or with a meta that lies about the binlogs or the dump: nothing is applied.
        $noImporter = new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project, null, $this->replayer());
        try {
            $noImporter->restore($manager->resolveChain($dbInc->getId()));
            self::fail('a dump was restored without an importer');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('no SQL importer', $e->getMessage());
        }

        $this->sidecar($dbInc, static function (array &$meta): void {
            $meta['binlog_events']['sha256'] = hash('sha256', 'other');
        });
        try {
            $manager->restoreChain($dbInc->getId());
            self::fail('a binlog that does not match its meta was replayed');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('events do not match their meta', $e->getMessage());
        }

        $this->rewrite($archiver, $dbOnly, static function (string $dir, array &$sums): void {
            unset($sums['database/dump.sql']);
        });
        try {
            $manager->restoreChain($dbInc->getId());
            self::fail('a chain without its dump was restored');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('the database dump is missing', $e->getMessage());
        }
    }

    public function testArchiverChainEdgeCases(): void
    {
        // A path that cannot be written as JSON in DELETED.json (invalid UTF-8) stops the backup.
        $archiver = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');
        $scope    = FileManifest::scopeOf(['config'], []);
        $base     = new FileManifest(["config/\xff" => [1, 1, hash('sha256', 'x')]], $scope);
        try {
            $archiver->create(new BackupOptions(includeDatabase: false, baseManifest: $base, parentId: 'p', chainId: 'p'));
            self::fail('an invalid UTF-8 deletion was archived');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('DELETED.json', $e->getMessage());
        }
        try {
            $base->sha256();
            self::fail('an invalid UTF-8 manifest was hashed');
        } catch (InvalidArgumentException) {
        }

        // Links leaving the project are not tracked.
        file_put_contents($this->root . '/outside.txt', 'outside');
        symlink($this->root . '/outside.txt', $this->project . '/config/link.txt');
        $full = $archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        self::assertArrayNotHasKey('config/link.txt', $full->getMeta()['file_manifest']['entries']);
        self::assertContains('1 link(s) pointing outside the project skipped.', $full->getWarnings());

        // Coordinates the server would never write.
        $badDump = EngineFixtures::script($this->tools, 'bad-dump', 'if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux"; exit 0; fi; for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done; printf -- "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE=\'bad\', SOURCE_LOG_POS=4;\\nCREATE TABLE t (id INT);\\n-- Dump completed\\n" > "$out"');
        $bad     = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, builtinDumper: new BuiltinMysqlDumper('mysql://bk@db/app', $badDump), binlogServer: BinlogFakes::server([]));
        try {
            $bad->create(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));
            self::fail('unusable coordinates were recorded');
        } catch (DumpFailedException $e) {
            self::assertStringContainsString('unusable binlog coordinates', $e->getMessage());
        }

        // The dumper refuses table rules with coordinates even when called directly, and a bad URL.
        try {
            (new BuiltinMysqlDumper('mysql://bk@db/app', $badDump))->dump($this->root . '/d.sql', ['t' => TableRule::Skip], false, null, true);
            self::fail('table rules with coordinates were accepted');
        } catch (InvalidBackupOptionsException) {
        }
        try {
            (new BuiltinMysqlDumper('nope'))->databaseName();
            self::fail('a bad URL was accepted');
        } catch (DumpFailedException) {
        }
    }

    public function testTheRealCaptureServiceInsideTheArchiver(): void
    {
        FakeBinlog::write($this->root . '/server/binlog.000002', [127, 1480, 2000]);
        $server = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000]]);
        file_put_contents($this->root . '/decoded', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "BEGIN\n/*!*/;\n") . "# End of log file\n");
        $capture  = new BinlogCapture($server, 'mysql://bk@db/app', new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->root)), $this->root . '/work', $this->clock);
        $archiver = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, builtinDumper: new BuiltinMysqlDumper('mysql://bk@db/app', EngineFixtures::fakeMysqldump($this->tools)), workDir: $this->root . '/work', binlogCapture: $capture, binlogServer: $server);
        $ticks    = 0;

        $inc = $archiver->create(new BackupOptions(
            includeFiles: false,
            heartbeat: static function () use (&$ticks): void {
                ++$ticks;
            },
            binlogFrom: new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID),
            parentId: 'p',
            chainId: 'p',
        ));
        self::assertSame(['binlog.000002'], array_column($inc->getMeta()['binlog_files'], 'name'));
        self::assertSame('binlog.000002', $inc->getMeta()['binlog_file']);
        self::assertGreaterThan(0, $ticks);
        self::assertSame(['.', '..'], scandir($this->root . '/work') ?: [], 'the capture outlived the backup');
    }

    public function testImportChainLinkErrorsAndLoops(): void
    {
        [$manager] = $this->filesManager();
        $a         = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $this->clock->sleep(60);
        $plain = $manager->createBackup(new BackupOptions(includeDatabase: false));
        $this->clock->sleep(60);
        $b = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $this->clock->sleep(60);
        $this->put('config/app.yaml', 'b2');
        $bInc = $manager->createBackup($manager->incrementalOptions($b->getId()));

        $this->storage = $this->root . '/elsewhere';
        [$target]      = $this->filesManager();
        foreach ([
            'a plain backup as a link' => [[$a->getAbsolutePath(), $plain->getAbsolutePath()], 'not an incremental backup'],
            'another chain'            => [[$a->getAbsolutePath(), $bInc->getAbsolutePath()], 'does not continue'],
        ] as $case => [$paths, $message]) {
            try {
                $target->importChain($paths);
                self::fail($case . ' was imported');
            } catch (CorruptArchiveException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
            self::assertSame([], $target->listBackups(), $case);
        }

        // A loop in the parent ids.
        $this->storage = $this->root . '/storage';
        $this->sidecar($b, static function (array &$meta) use ($bInc): void {
            $meta['parent_id'] = $bInc->getId();
        });
        try {
            $manager->resolveChain($bInc->getId());
            self::fail('a loop resolved');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('loops', $e->getMessage());
        }
    }

    public function testToolCornerCases(): void
    {
        self::assertNull(BinlogEventTimes::parse("#700101  0:00:00 server id 1  end_log_pos 1 CRC32 0x1 \tQuery"));

        $slowVersion = EngineFixtures::script($this->tools, 'slow-version', 'sleep 5');
        $checks      = (new BinlogPrerequisites(BinlogFakes::server([]), ['mysqlbinlog' => $slowVersion], 604800, 1))->check();
        self::assertTrue($checks[0]->isFailure());
        try {
            (new MysqlBinlogTool($slowVersion, 1))->assertUsable();
            self::fail('a hanging --version was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }

        // A password that an option file cannot hold (control character).
        $url      = 'mysql://bk:a%0Ab@127.0.0.1/app';
        $replayer = new BinlogReplayer($url, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->tools)), BinlogFakes::mysqlClient($this->tools), 30, $this->root . '/work');
        file_put_contents($this->root . '/replay.sql', 'SELECT 1;');
        try {
            $replayer->apply($this->root . '/replay.sql');
            self::fail('an option file with a newline was written');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('control characters', $e->getMessage());
        }

        // A server that answers VERSION() but has no binary log status.
        $quiet = EngineFixtures::script($this->tools, 'quiet-mysql', 'case "$*" in *"VERSION()"*) echo "9.7.2";; esac; exit 0');
        try {
            (new MysqlClientBinlogServer('mysql://bk@db/app', $quiet, 30, $this->root . '/work'))->status();
            self::fail('an empty status was accepted');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('no binary log status', $e->getMessage());
        }
    }

    /**
     * @return array{0: SiteBackupManager, 1: BackupArchiver}
     */
    private function filesManager(): array
    {
        $archiver = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');

        return [$this->manager($archiver, new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project)), $archiver];
    }

    /**
     * @return array{0: SiteBackupManager, 1: BackupArchiver}
     */
    private function databaseManager(): array
    {
        $url  = 'mysql://bk@127.0.0.1:3306/app';
        $dump = EngineFixtures::script($this->tools, 'fake-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            printf -- "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;\nCREATE TABLE t (id INT);\n-- Dump completed\n" > "$out"
            SH);
        $server = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000]]);
        (new Filesystem())->mkdir($this->tools . '/server');
        // What the fake mysqlbinlog copies: binlog.000002 (the dump's file, 2000 bytes), then 158-byte logs.
        FakeBinlog::write($this->tools . '/server/binlog.000002', [127, 1480, 2000]);
        for ($n = 3; $n < 8; ++$n) {
            FakeBinlog::write($this->tools . '/server/binlog.00000' . $n, [127, 158]);
        }
        $capture  = new BinlogCapture($server, $url, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->tools)), $this->root . '/work', $this->clock);
        $archiver = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, builtinDumper: new BuiltinMysqlDumper($url, $dump), workDir: $this->root . '/work', binlogCapture: $capture, binlogServer: $server);
        $restorer = new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project, new DumpImporter($url, BinlogFakes::mysqlClient($this->tools)), $this->replayer());

        // The fake mysqlbinlog copies <tools>/server/<name>; event times come from <tools>/decoded.
        file_put_contents($this->tools . '/decoded', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "BEGIN\n/*!*/;\n") . "# End of log file\n");
        // Two logs in one decoded stream (the second chain captures binlog.000002 and binlog.000003).
        file_put_contents($this->tools . '/decoded.2', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "BEGIN\n/*!*/;\n") . FakeBinlog::start(4) . FakeBinlog::event(127, 158, 'Xid = 1', "COMMIT/*!*/;\n") . "# End of log file\n");

        return [$this->manager($archiver, $restorer), $archiver];
    }

    private function replayer(): BinlogReplayer
    {
        return new BinlogReplayer('mysql://bk@127.0.0.1:3306/app', new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->tools)), BinlogFakes::mysqlClient($this->tools), 30, $this->root . '/work');
    }

    private function manager(BackupArchiver $archiver, ChainRestorer $restorer): SiteBackupManager
    {
        return new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: new MemoryHistoryStorage(),
            clock: $this->clock,
            chainRestorer: $restorer,
        );
    }

    /**
     * Rebuilds an archive after $mutate($payloadDir, &$checksums) and rewrites MANIFEST.json and the sidecar to match:
     * what a forger with write access to storage_dir could produce (everything but the meta's own consistency).
     *
     * @param callable(string, array<string, string>): void $mutate
     */
    private function rewrite(BackupArchiver $archiver, BackupArtifact $artifact, callable $mutate): void
    {
        $dir = $this->root . '/rewrite-' . ++$this->tick;
        $archiver->extractTo($artifact, $dir);
        $manifest = json_decode((string) file_get_contents($dir . '/MANIFEST.json'), true);
        self::assertIsArray($manifest);
        $sums = $manifest['checksums'];
        $mutate($dir, $sums);
        $manifest['checksums'] = $sums;
        file_put_contents($dir . '/MANIFEST.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        (new Process(['tar', '-czf', $artifact->getAbsolutePath(), '-C', $dir, ...$entries]))->mustRun();

        $path = $this->storage . '/' . $artifact->getId() . '.meta.json';
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        $data['checksums']      = $sums;
        $data['archive_sha256'] = hash_file('sha256', $artifact->getAbsolutePath());
        $data['size_bytes']     = filesize($artifact->getAbsolutePath());
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param callable(array<string, mixed>): void $mutate
     */
    private function sidecar(BackupArtifact $artifact, callable $mutate): void
    {
        $path = $this->storage . '/' . $artifact->getId() . '.meta.json';
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        $meta = $data['meta'];
        $mutate($meta);
        $data['meta'] = $meta;
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function snapshot(BackupArtifact $artifact): array
    {
        return [(string) file_get_contents($artifact->getAbsolutePath()), (string) file_get_contents($this->storage . '/' . $artifact->getId() . '.meta.json')];
    }

    /**
     * @param array{0: string, 1: string} $snapshot
     */
    private function restoreSnapshot(BackupArtifact $artifact, array $snapshot): void
    {
        file_put_contents($artifact->getAbsolutePath(), $snapshot[0]);
        file_put_contents($this->storage . '/' . $artifact->getId() . '.meta.json', $snapshot[1]);
    }

    private function put(string $relative, string $content): void
    {
        (new Filesystem())->mkdir(dirname($this->project . '/' . $relative));
        file_put_contents($this->project . '/' . $relative, $content);
        touch($this->project . '/' . $relative, $this->clock->now()->getTimestamp() - 1000 + ++$this->tick);
    }
}
