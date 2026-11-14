<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Command\RestoreChainCommand;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\MysqlOptionFile;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\BinlogFilterException;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\BackupChain;
use Nowo\SiteBackupBundle\Incremental\BinlogCapture;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\BinlogSchemaFilter;
use Nowo\SiteBackupBundle\Incremental\CapturedBinlog;
use Nowo\SiteBackupBundle\Incremental\ChainPaths;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use Nowo\SiteBackupBundle\Incremental\ChainVerifier;
use Nowo\SiteBackupBundle\Incremental\DumpObjects;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Incremental\IncrementalOptions;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Incremental\MysqlClientBinlogServer;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Remote\InMemoryRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Remote\RemoteObject;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Restore\RestorePathGuard;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\ForwardingTarget;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryHistoryStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function implode;
use function rewind;
use function str_repeat;
use function strlen;
use function symlink;
use function touch;

/**
 * Audit PP (SiteBackup PR #62): every finding as a red-first test.
 */
final class PpAuditFixesTest extends TestCase
{
    private const URL = 'mysql://bk@127.0.0.1:3306/app';

    private string $root;

    private string $project;

    private string $tools;

    private MockClock $clock;

    private int $tick = 0;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-pp-');
        $this->project = $this->root . '/project';
        $this->tools   = $this->root . '/tools';
        (new Filesystem())->mkdir([$this->project . '/config', $this->root . '/work', $this->tools . '/server']);
        $this->clock = new MockClock('2026-11-01 00:00:00 UTC');
        $this->put('config/app.yaml', 'a');
        FakeBinlog::write($this->tools . '/server/binlog.000002', [127, 1480, 2000]);
        for ($n = 3; $n < 8; ++$n) {
            FakeBinlog::write($this->tools . '/server/binlog.00000' . $n, [127, 158]);
        }
        // What the fake mysqlbinlog "decodes" (filtered captures run it through the schema filter).
        file_put_contents($this->tools . '/decoded', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "use `app`/*!*/;\nCREATE TABLE newt (id INT)\n/*!*/;\n") . "# End of log file\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testH1TheTargetSchemaIsComparedWithTheDumpBeforeAnythingIsTouched(): void
    {
        [$manager] = $this->databaseManager();
        $full      = $manager->createBackup(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));
        self::assertNotNull($full->getMeta()['snapshot_at'] ?? null);
        $this->clock->sleep(3600);
        $inc = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertSame(IncrementalArtifact::FILTERED, $inc->getMeta()['binlog_capture']);
        self::assertArrayHasKey(ChainPaths::EVENTS, $inc->getChecksums());
        self::assertArrayNotHasKey(ChainPaths::BINLOG_DIRECTORY . 'binlog.000002', $inc->getChecksums(), 'raw logs leave the capture directory only in raw mode');

        // Scenario A: a table created after the full still exists on the target -> refused, nothing applied.
        file_put_contents($this->tools . '/schemata', "app\n");
        file_put_contents($this->tools . '/objects', "TABLE\tt\nTABLE\tnewt\nVIEW\tv_new\nPROCEDURE\tp\n");
        try {
            $manager->restoreChain($inc->getId());
            self::fail('a restore onto a schema with newer objects was attempted');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('3 object(s) the full backup does not re-create (TABLE newt, VIEW v_new, PROCEDURE p', $e->getMessage());
            self::assertStringContainsString('--recreate-database', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->tools . '/order', 'something was applied');

        // Opt-in: drop and re-create the schema (keeping its charset), then import and replay.
        $report = $manager->restoreChain($inc->getId(), null, 'ops', null, true);
        self::assertTrue($report->complete);
        self::assertStringContainsString('schema dropped and re-created', $report->steps[0]->detail);
        $ddl = (string) file_get_contents($this->tools . '/ddl');
        self::assertStringContainsString('DROP DATABASE IF EXISTS `app`; CREATE DATABASE `app` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci', $ddl);
        self::assertSame("import\nreplay\n", file_get_contents($this->tools . '/order'));
        self::assertStringContainsString('CREATE TABLE newt', (string) file_get_contents($this->tools . '/replayed.sql'));
        self::assertEquals(new DateTimeImmutable('2026-11-01 00:30:00 UTC'), $report->databaseAt);

        // A fresh server: the schema is created, nothing to compare.
        (new Filesystem())->remove([$this->tools . '/schemata', $this->tools . '/ddl']);
        $report = $manager->restoreChain($inc->getId());
        self::assertStringContainsString('schema created', $report->steps[0]->detail);
        self::assertStringContainsString('CREATE DATABASE `app`', (string) file_get_contents($this->tools . '/ddl'));

        // Objects the dump re-creates are fine.
        file_put_contents($this->tools . '/schemata', "app\n");
        file_put_contents($this->tools . '/objects', "TABLE\tt\n");
        self::assertTrue($manager->restoreChain($inc->getId())->complete);

        $command = new CommandTester(new RestoreChainCommand($manager));
        self::assertSame(Command::SUCCESS, $command->execute(['head' => $inc->getId(), '--force' => true, '--recreate-database' => true]));
        self::assertStringContainsString('Database at 2026-11-01T00:30:00+00:00.', $command->getDisplay());
    }

    public function testH1RecreateNeedsADatabaseChainAndTheDumpParser(): void
    {
        [$manager, $archiver] = $this->filesManager();
        $full                 = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        try {
            $manager->restoreChain($full->getId(), null, null, null, true);
            self::fail('--recreate-database without a database part was accepted');
        } catch (ChainBrokenException) {
        }

        file_put_contents($this->root . '/dump.sql', implode("\n", [
            '-- Table structure for table `t`',
            'DROP TABLE IF EXISTS `t`;',
            'CREATE TABLE `t` (',
            '/*!50001 DROP VIEW IF EXISTS `v`*/;',
            '/*!50001 CREATE VIEW `v` AS SELECT 1 AS `id`*/;',
            '/*!50001 VIEW `v` AS select 1 */;',
            '/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER `tr``g` BEFORE INSERT ON `t` FOR EACH ROW SET NEW.id = 1 */;;',
            "INSERT INTO `t` VALUES (1,'CREATE TABLE `fake`');",
        ]));
        self::assertSame(['TABLE t' => true, 'VIEW v' => true, 'TRIGGER tr`g' => true], DumpObjects::parse($this->root . '/dump.sql'));
        self::assertSame([], DumpObjects::parse($this->root . '/missing.sql'));
        unset($archiver);
    }

    public function testM3FilteredCapturesFailClosedAndRefuseNonRowLogs(): void
    {
        $server  = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000]]);
        $capture = new BinlogCapture($server, self::URL, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->tools)), $this->root . '/work', $this->clock);
        $from    = new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID);

        // An event that cannot be attributed: nothing is archived, the capture says why.
        file_put_contents($this->tools . '/decoded', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "use `app`/*!*/;\nDROP DATABASE app\n/*!*/;\n") . "# End of log file\n");
        try {
            $capture->capture($from, 'app');
            self::fail('an unattributable event was archived');
        } catch (BinlogFilterException $e) {
            self::assertStringContainsString('statement on the whole schema', $e->getMessage());
            self::assertCount(1, $e->getProblems());
        }
        self::assertSame(['.', '..'], scandir($this->root . '/work') ?: [], 'the raw logs outlived a failed capture');

        // PP-L4: a server that is not on ROW is refused before anything is read.
        try {
            (new BinlogCapture(BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000]], format: 'MIXED'), self::URL, new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->tools)), $this->root . '/work'))->capture($from, 'app');
            self::fail('a MIXED server was captured');
        } catch (BinlogUnavailableException $e) {
            self::assertStringContainsString('binlog_format=ROW', $e->getMessage());
        }

        try {
            new BinlogCapture($server, self::URL, mode: 'sometimes');
            self::fail('an unknown mode was accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            new IncrementalArtifact($from, $from, 'app', [new CapturedBinlog('binlog.000002', '/x', 1, hash('sha256', 'x'))], '/x', mode: IncrementalArtifact::FILTERED);
            self::fail('a filtered capture without events was accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            new IncrementalArtifact($from, $from, 'app', [], '/x', mode: 'other');
            self::fail('an unknown capture mode was accepted');
        } catch (InvalidArgumentException) {
        }
    }

    public function testM2RawChainsAreCheckedAtRestore(): void
    {
        [$manager, $archiver] = $this->databaseManager(IncrementalArtifact::RAW);
        $full                 = $manager->createBackup(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));
        $this->clock->sleep(3600);
        $inc = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertSame(IncrementalArtifact::RAW, $inc->getMeta()['binlog_capture']);
        self::assertArrayHasKey(ChainPaths::BINLOG_DIRECTORY . 'binlog.000002', $inc->getChecksums());

        // Scenario B in a raw capture: another schema's DDL from an app session -> the restore is refused.
        file_put_contents($this->tools . '/decoded', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "use `app`/*!*/;\nDROP TABLE `other`.`victim`\n/*!*/;\n") . "# End of log file\n");
        try {
            $manager->restoreChain($inc->getId());
            self::fail('a replay touching another schema was attempted');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('outside schema app', $e->getMessage());
            self::assertStringNotContainsString('victim', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->tools . '/order');
        unset($archiver);
    }

    public function testL10OneChainOneFormatAndKey(): void
    {
        $full = ChainFixtures::full('full', ['format' => 'tar.gz']);
        foreach ([
            [new BackupOptions(format: ArchiveFormat::Zip), 'same format'],
            [new BackupOptions(password: ArchivePassword::fromString('pass-REDACTED-TEST-long-enough'), keyId: 'k1'), 'not encrypted'],
        ] as [$base, $message]) {
            try {
                IncrementalOptions::fromParentMeta('full', $full->getMeta(), $base);
                self::fail($message);
            } catch (InvalidBackupOptionsException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $encrypted = ['format' => 'zip', 'encrypted' => true, 'key_id' => 'k1'] + $full->getMeta();
        try {
            IncrementalOptions::fromParentMeta('full', $encrypted, new BackupOptions(format: ArchiveFormat::Zip, password: ArchivePassword::fromString('pass-REDACTED-TEST-long-enough'), keyId: 'k2'));
            self::fail('a rotated key continued the chain');
        } catch (InvalidBackupOptionsException $e) {
            self::assertStringContainsString('key id "k1"', $e->getMessage());
        }
        // Without explicit options, the parent's format is kept.
        $zip = IncrementalOptions::fromParentMeta('full', ['format' => 'zip'] + $full->getMeta());
        self::assertSame(ArchiveFormat::Zip, $zip->format);

        // Restore side: a chain mixing encryption is refused before anything is extracted.
        $mixed    = ChainFixtures::incremental('i1', $full, 1, ['encrypted' => true, 'key_id' => 'k1', 'encryption' => 'age-scrypt']);
        $problems = ChainVerifier::verify((new BackupChain([$full, $mixed]))->links);
        self::assertStringContainsString('one chain, one format and key', implode(' ', $problems));
    }

    public function testL7FilesThatCannotBeWrittenMakeTheRestoreIncomplete(): void
    {
        [$manager] = $this->filesManager();
        $full      = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        (new Filesystem())->remove($this->project . '/config/app.yaml');
        symlink($this->root . '/outside-target', $this->project . '/config/app.yaml');

        $report = $manager->restoreChain($full->getId());
        self::assertFalse($report->complete);
        self::assertSame(ChainRestoreStep::INCOMPLETE, $report->steps[0]->status);
        self::assertStringContainsString('NOT written: config/app.yaml (a link or directory is in the way)', $report->steps[0]->detail);
        self::assertFileDoesNotExist($this->root . '/outside-target', 'L3: a link at the destination was followed');

        $command = new CommandTester(new RestoreChainCommand($manager));
        self::assertSame(Command::FAILURE, $command->execute(['head' => $full->getId(), '--force' => true]));
        self::assertStringContainsString('INCOMPLETE', $command->getDisplay());
        self::assertStringContainsString('Files as of', $command->getDisplay());
    }

    public function testL9ReservedPayloadPathsAreNotCollected(): void
    {
        $this->put('MANIFEST.json', '{}');
        $this->put('database/dump.sql', 'x');
        $this->put('.nowo-site-backup/DELETED.json', '[]');
        $archiver = new BackupArchiver($this->project, $this->root . '/storage', [''], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');
        $full     = $archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true));

        self::assertSame(['config/app.yaml'], array_keys($full->getMeta()['file_manifest']['entries']));
        self::assertContains('3 file(s) at a path the archive reserves (MANIFEST.json, database/dump.sql, .nowo-site-backup/) skipped.', $full->getWarnings());
    }

    public function testM5SidecarsStaySmallAndPruneNeverSkipsSilently(): void
    {
        $clock     = new MockClock(new DateTimeImmutable('-10 days'));
        $archiver  = new BackupArchiver($this->project, $this->root . '/storage', ['config'], [], null, 60, clock: $clock);
        $publisher = new RemoteBackupPublisher(clock: new MockClock(new DateTimeImmutable('-1 day')), logger: $logger = new RecordingLogger());
        for ($i = 0; $i < 600; ++$i) {
            $this->put('config/f' . $i . '.yaml', 'x' . $i);
        }

        foreach ([false, true] as $keepsMetadata) {
            $target = new InMemoryRemoteTarget($keepsMetadata);
            $old    = $archiver->create();
            $publisher->publish($old, $target, 'p/', 'p/' . $old->getId() . '.tar.gz');
            $clock->sleep(60);
            $full = $archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true));
            $publisher->publish($full, $target, 'p/', 'p/' . $full->getId() . '.tar.gz');
            $sidecar = (string) stream_get_contents($target->openRead('p/' . $full->getId() . '.tar.gz.backup.json'));
            self::assertLessThan(16 * 1024, strlen($sidecar), '600 files must not bloat the sidecar');

            // A sidecar that cannot be read (oversized, broken): reported and logged; with the chain id on the object
            // its chain is kept, without it nothing is pruned.
            $this->replace($target, 'p/' . $full->getId() . '.tar.gz.backup.json', str_repeat('x', 70 * 1024));
            $clock->sleep(60);
            $next = $archiver->create();
            $publisher->publish($next, $target, 'p/', 'p/' . $next->getId() . '.tar.gz');
            $report = $publisher->prune($target, 'p/', new RetentionPolicy(keepLast: 1), $next->getId());

            self::assertSame('UnreadableSidecar', $report->errors['p/' . $full->getId() . '.tar.gz.backup.json'] ?? null);
            self::assertNotContains('p/' . $full->getId() . '.tar.gz', $report->deletedKeys);
            // With the chain id on the object its chain is kept; without it and without any readable chain sidecar
            // under the prefix (PP-D1-I1), pruning goes on as in 1.35. The unreadable archive is never deleted.
            self::assertSame(['p/' . $old->getId() . '.tar.gz'], $report->deletedKeys);
        }
        self::assertStringContainsString('remote sidecar unreadable', implode("\n", $logger->lines()));
    }

    public function testL11OrphansNeverIncludeChainMembers(): void
    {
        $archiver  = new BackupArchiver($this->project, $this->root . '/storage', ['config'], [], null, 60, clock: new MockClock(new DateTimeImmutable('-10 days')));
        $publisher = new RemoteBackupPublisher(clock: new MockClock(new DateTimeImmutable('+5 days')));
        $target    = new InMemoryRemoteTarget(false);

        $full = $archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $inc  = $archiver->create(IncrementalOptions::fromParentMeta($full->getId(), $full->getMeta()));
        $publisher->publish($full, $target, 'p/', 'p/' . $full->getId() . '.tar.gz');
        $publisher->publish($inc, $target, 'p/', 'p/' . $inc->getId() . '.tar.gz');
        // The full backup's sidecar upload "failed": its archive has no sidecar, but the incremental names it.
        $target->delete('p/' . $full->getId() . '.tar.gz.backup.json');
        $this->replace($target, 'p/stray.tar.gz', 'x');

        $orphans = array_map(static fn (RemoteObject $o): string => $o->key, $publisher->orphans($target, 'p/', 3600));
        self::assertSame(['p/stray.tar.gz'], $orphans);

        // With object metadata, a chain member is never an orphan even before a child names it.
        $withMeta = new InMemoryRemoteTarget(true);
        $publisher->publish($inc, $withMeta, 'p/', 'p/' . $inc->getId() . '.tar.gz');
        $withMeta->delete('p/' . $inc->getId() . '.tar.gz.backup.json');
        self::assertSame([], $publisher->orphans($withMeta, 'p/', 3600));
    }

    public function testM4L6SeparateAccountsTlsAndReadingAParentArchive(): void
    {
        $tls = MysqlOptionFile::contents(['database' => 'app', 'host' => 'db', 'port' => 3306, 'socket' => null, 'user' => 'u', 'pass' => ''], ['--ssl-mode=VERIFY_IDENTITY', '--ssl-ca=/etc/ca.pem', '--tls-version=TLSv1.3', '--column-statistics=0', '--ssl-fips-mode']);
        self::assertStringContainsString("ssl-mode=\"VERIFY_IDENTITY\"\nssl-ca=\"/etc/ca.pem\"\ntls-version=\"TLSv1.3\"\nssl-fips-mode\n", $tls);
        self::assertStringNotContainsString('column-statistics', $tls);

        // The coordinates dump runs with the binlog account, which must name the same database.
        $dump   = EngineFixtures::script($this->tools, 'args-dump', 'if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux"; exit 0; fi; for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done; echo "-- $* MYSQL_PWD=$MYSQL_PWD" > "$out"; echo "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE=\'binlog.000002\', SOURCE_LOG_POS=4;" >> "$out"; printf -- "CREATE TABLE t (id INT);\\n-- Dump completed\\n" >> "$out"');
        $dumper = new BuiltinMysqlDumper('mysql://app:a@db/app', $dump, binlogDatabaseUrl: 'mysql://bk:b@db/app');
        $dumper->dump($this->root . '/coords.sql', recordBinlogCoordinates: true);
        self::assertStringContainsString('--user=bk', (string) file_get_contents($this->root . '/coords.sql'));
        $dumper->dump($this->root . '/plain.sql');
        self::assertStringContainsString('--user=app', (string) file_get_contents($this->root . '/plain.sql'));
        try {
            (new BuiltinMysqlDumper('mysql://app:a@db/app', $dump, binlogDatabaseUrl: 'mysql://bk:b@db/other'))->dump($this->root . '/x.sql', recordBinlogCoordinates: true);
            self::fail('another database was accepted');
        } catch (DumpFailedException $e) {
            self::assertStringContainsString('same database', $e->getMessage());
        }

        // A parent whose local copy is gone: its archive gives the manifest.
        [$manager, $archiver] = $this->filesManager();
        $full                 = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $options              = $manager->incrementalOptionsFromArchive($full->getAbsolutePath());
        self::assertSame($full->getMeta()['file_manifest_sha256'], $options->baseManifest?->sha256());
        try {
            $manager->incrementalOptionsFromArchive($this->root . '/missing.tar.gz');
            self::fail('a missing archive was read');
        } catch (InvalidBackupOptionsException) {
        }
        try {
            $archiver->readArchiveMeta($this->root . '/missing.tar.gz');
            self::fail('a missing archive was read');
        } catch (CorruptArchiveException) {
        }
    }

    public function testI2NonChainHostsKeepTheOldRetentionOrder(): void
    {
        $archiver = new BackupArchiver($this->project, $this->root . '/storage', ['config'], [], null, 60, clock: $this->clock);
        $plain    = new SiteBackupManager($archiver, new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage(), clock: $this->clock);
        $ids      = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $plain->createBackup()->getId();
            $this->clock->sleep(60);
        }

        $history = new MemoryHistoryStorage();
        $manager = new SiteBackupManager($archiver, new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []), $history, clock: $this->clock, localRetention: new RetentionPolicy(keepLast: 1));
        $manager->createBackup();
        $deletes = [];
        foreach (array_reverse($history->list()) as $entry) {
            if ($entry->getAction() === 'delete') {
                $deletes[] = $entry->getBackupId();
            }
        }
        self::assertSame($ids, $deletes, 'oldest first, as in 1.35');
    }

    public function testCornerCasesOfTheNewPaths(): void
    {
        // The SPI's schema helpers through the client.
        $server = new MysqlClientBinlogServer(self::URL, BinlogFakes::mysqlClient($this->tools), 30, $this->root . '/work');
        self::assertSame(['app', 'mysql'], $server->schemas());
        try {
            $server->createSchema('bad name');
            self::fail('an unsafe schema name was accepted');
        } catch (InvalidArgumentException) {
        }

        // Capture modes and chains.
        $full     = ChainFixtures::full();
        $filtered = ChainFixtures::incremental('i1', $full, 1, ['binlog_capture' => 'filtered']);
        foreach ([
            'unknown mode'   => [[$full, ChainFixtures::incremental('i1', $full, 1, ['binlog_capture' => 'other'])], 'unknown binlog capture mode'],
            'no events'      => [[$full, $filtered], 'filtered capture without its events file'],
            'mixed captures' => [[$full, ChainFixtures::incremental('i1', $full, 1, ['binlog_capture' => 'raw']), ChainFixtures::incremental('i2', ChainFixtures::incremental('i1', $full, 1), 2, ['binlog_capture' => 'filtered'])], 'mixes raw and filtered'],
        ] as $case => [$links, $message]) {
            self::assertStringContainsString($message, implode(' ', ChainVerifier::verify((new BackupChain($links))->links)), $case);
        }

        // A cut that ends the first of two inputs skips the second; a group left open at the end of a log fails.
        $a = $this->root . '/a.sql';
        $b = $this->root . '/b.sql';
        file_put_contents($a, "# at 4\n#261101 10:00:00 server id 1  end_log_pos 2 CRC32 0x1 \tXid = 1\nCOMMIT/*!*/;\n");
        file_put_contents($b, "# at 4\n#261101 09:00:00 server id 1  end_log_pos 2 CRC32 0x1 \tXid = 2\nCOMMIT/*!*/;\n");
        $result = BinlogSchemaFilter::run([$a, $b], $this->root . '/cut.sql', 'app', 'check', new DateTimeImmutable('2026-11-01 09:30:00 UTC'));
        self::assertTrue($result->cut);
        self::assertStringNotContainsString('Xid = 2', (string) file_get_contents($this->root . '/cut.sql'));
        file_put_contents($a, "# at 4\n#261101 10:00:00 server id 1  end_log_pos 2 CRC32 0x1 \tTable_map: `app`.`t` mapped to number 1\n# End of log file\n");
        self::assertFalse(BinlogSchemaFilter::run([$a], $this->root . '/open.sql', 'app', 'filter')->isClean());

        // The restore connection must name the chain's database; a dump-only chain needs no replayer.
        [$manager, $archiver] = $this->databaseManager();
        $dbFull               = $manager->createBackup(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));
        $elsewhere            = new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project, new DumpImporter('mysql://bk@127.0.0.1/other', BinlogFakes::mysqlClient($this->tools)));
        try {
            $elsewhere->restore($manager->resolveChain($dbFull->getId()));
            self::fail('an import into another database was accepted');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('names another one', $e->getMessage());
        }
        $dumpOnly = new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project, new DumpImporter(self::URL, BinlogFakes::mysqlClient($this->tools)));
        self::assertTrue($dumpOnly->restore($manager->resolveChain($dbFull->getId()))->complete);

        // Raw chains: a binlog whose meta hash differs is refused.
        [$rawManager] = $this->databaseManager(IncrementalArtifact::RAW);
        $rawFull      = $rawManager->createBackup(new BackupOptions(includeFiles: false, recordBinlogCoordinates: true));
        $this->clock->sleep(3600);
        $rawInc = $rawManager->createBackup($rawManager->incrementalOptions($rawFull->getId()));
        $path   = $this->root . '/storage/' . $rawInc->getId() . '.meta.json';
        $data   = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        $data['meta']['binlog_files'][0]['sha256'] = hash('sha256', 'other');
        file_put_contents($path, (string) json_encode($data));
        try {
            $rawManager->restoreChain($rawInc->getId());
            self::fail('a raw binlog that does not match its meta was replayed');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('binary log binlog.000002 does not match its meta', $e->getMessage());
        }

        // A file that cannot be written after the database steps: a half-applied restore, reported as such.
        [$filesManager] = $this->filesManager();
        $files          = $filesManager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        chmod($this->project . '/config', 0o555);
        try {
            $filesManager->restoreChain($files->getId());
            self::fail('an unwritable file was not reported');
        } catch (ChainRestoreException $e) {
            self::assertSame(ChainRestoreStep::FAILED, $e->getReport()->steps[0]->status);
        } finally {
            chmod($this->project . '/config', 0o755);
        }

        // Remote: a head() that fails while reading an unknown sidecar's object counts as unknown.
        $inner  = new InMemoryRemoteTarget(false);
        $broken = new class($inner) extends ForwardingTarget {
            public function head(string $key): ?RemoteObject
            {
                throw new RuntimeException('denied');
            }
        };
        $this->replace($inner, 'p/x.tar.gz', 'x');
        $this->replace($inner, 'p/x.tar.gz.backup.json', '{');
        $report = (new RemoteBackupPublisher())->prune($broken, 'p/', new RetentionPolicy(keepLast: 0), 'none');
        self::assertSame(['p/x.tar.gz.backup.json' => 'UnreadableSidecar'], $report->errors);
    }

    /**
     * @return array{0: SiteBackupManager, 1: BackupArchiver}
     */
    private function databaseManager(string $mode = IncrementalArtifact::FILTERED): array
    {
        $dump = EngineFixtures::script($this->tools, 'fake-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            printf -- "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;\nCREATE TABLE \`t\` (id INT);\n-- Dump completed\n" > "$out"
            SH);
        $server   = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000]]);
        $tool     = new MysqlBinlogTool(BinlogFakes::mysqlbinlog($this->tools));
        $client   = BinlogFakes::mysqlClient($this->tools);
        $capture  = new BinlogCapture($server, self::URL, $tool, $this->root . '/work', $this->clock, mode: $mode);
        $archiver = new BackupArchiver($this->project, $this->root . '/storage', ['config'], [], null, 60, clock: $this->clock, builtinDumper: new BuiltinMysqlDumper(self::URL, $dump), workDir: $this->root . '/work', binlogCapture: $capture, binlogServer: $server);
        $restorer = new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project, new DumpImporter(self::URL, $client), new BinlogReplayer(self::URL, $tool, $client, 30, $this->root . '/work'));

        return [$this->manager($archiver, $restorer), $archiver];
    }

    /**
     * @return array{0: SiteBackupManager, 1: BackupArchiver}
     */
    private function filesManager(): array
    {
        $archiver = new BackupArchiver($this->project, $this->root . '/storage', ['config'], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');

        return [$this->manager($archiver, new ChainRestorer($archiver, new RestorePathGuard($this->project, [], [], new SecretPathPolicy()), $this->project)), $archiver];
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

    private function replace(InMemoryRemoteTarget $target, string $key, string $data): void
    {
        $target->delete($key);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $data);
        rewind($stream);
        $target->upload($stream, $key, strlen($data), []);
    }

    private function put(string $relative, string $content): void
    {
        (new Filesystem())->mkdir(dirname($this->project . '/' . $relative));
        file_put_contents($this->project . '/' . $relative, $content);
        touch($this->project . '/' . $relative, $this->clock->now()->getTimestamp() - 1000 + ++$this->tick);
    }
}
