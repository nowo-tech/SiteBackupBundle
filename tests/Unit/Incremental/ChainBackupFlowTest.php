<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\BinlogCaptureInterface;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\CapturedBinlog;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Incremental\MysqlBinlogTool;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Restore\RestorePathGuard;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryHistoryStorage;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\MemoryRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function array_keys;
use function array_slice;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function sort;
use function sprintf;
use function time;
use function touch;
use function unlink;

use const DATE_ATOM;
use const FILE_APPEND;

/**
 * Full → writes → incrementals → chain restore (point in time), with stand-in database tools.
 */
final class ChainBackupFlowTest extends TestCase
{
    private string $root;

    private string $project;

    private string $storage;

    private MockClock $clock;

    private MemoryHistoryStorage $history;

    private int $tick = 0;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-chain-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        (new Filesystem())->mkdir([$this->project . '/config', $this->project . '/public', $this->root . '/work', $this->root . '/tools']);
        $this->clock   = new MockClock('2026-11-01 00:00:00 UTC');
        $this->history = new MemoryHistoryStorage();

        $this->put('config/app.yaml', "a: 1\n");
        $this->put('config/old.yaml', "old: 1\n");
        $this->put('public/index.php', "<?php\n");
        $this->put('.env.local', 'CANARY-env');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testFileChainDeltasAndPointInTimeRestore(): void
    {
        $manager = $this->manager();
        $full    = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $meta    = $full->getMeta();

        self::assertSame('full', $meta['type']);
        self::assertSame($full->getId(), $meta['chain_id']);
        self::assertNull($meta['parent_id']);
        self::assertSame(['config/app.yaml', 'config/old.yaml', 'public/index.php'], array_keys($meta['file_manifest']['entries']));
        self::assertSame(FileManifest::fromArray($meta['file_manifest'])->sha256(), $meta['file_manifest_sha256']);

        // Writes: change, delete, add, touch without changing.
        $this->clock->sleep(3600);
        $this->put('config/app.yaml', "a: 2\n");
        unlink($this->project . '/config/old.yaml');
        $this->put('public/new.txt', 'new');
        touch($this->project . '/public/index.php', $this->mtime());

        $i1 = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertSame('incremental', $i1->getMeta()['type']);
        self::assertSame($full->getId(), $i1->getMeta()['parent_id']);
        self::assertSame($meta['file_manifest_sha256'], $i1->getMeta()['base_manifest_sha256']);
        self::assertSame(['.nowo-site-backup/DELETED.json', 'config/app.yaml', 'public/new.txt'], $this->sorted(array_keys($i1->getChecksums())));
        self::assertSame(1, $i1->getMeta()['files_deleted']);
        self::assertSame(2, $i1->getMeta()['files_changed']);
        $this->assertEntries(['config/app.yaml', 'public/index.php', 'public/new.txt'], $i1);

        // A second delta: unchanged files (old mtime) are not even read.
        $this->clock->sleep(3600);
        $this->put('public/new.txt', 'newer');
        $i2 = $manager->createBackup($manager->incrementalOptions($i1->getId()));
        self::assertSame(['.nowo-site-backup/DELETED.json', 'public/new.txt'], $this->sorted(array_keys($i2->getChecksums())));
        self::assertSame($i1->getMeta()['file_manifest']['entries']['public/index.php'], $i2->getMeta()['file_manifest']['entries']['public/index.php']);

        try {
            $manager->incrementalOptions($i1->getId());
            self::fail('a second branch was allowed');
        } catch (InvalidBackupOptionsException $e) {
            self::assertStringContainsString('already has an incremental', $e->getMessage());
        }

        // Lose everything, then restore to the head.
        $this->put('config/app.yaml', 'broken');
        $this->put('config/old.yaml', 'resurrected');
        unlink($this->project . '/public/new.txt');
        $report = $manager->restoreChain($i2->getId(), null, 'ops');
        self::assertTrue($report->complete);
        self::assertSame("a: 2\n", $this->read('config/app.yaml'));
        self::assertSame('newer', $this->read('public/new.txt'));
        self::assertFileDoesNotExist($this->project . '/config/old.yaml');
        self::assertSame('CANARY-env', $this->read('.env.local'));
        self::assertSame([ChainRestoreStep::PART_FILES, ChainRestoreStep::PART_DELETIONS], array_map(static fn (ChainRestoreStep $s): string => $s->part, $report->steps));
        self::assertSame(['restore_chain_complete', 'restore_chain_start'], array_map(static fn (BackupHistoryEntry $e): string => $e->getAction(), array_slice($this->history->list(), 0, 2)));

        // Point in time between i1 and i2: files as of i1.
        $report = $manager->restoreChain($i2->getId(), new DateTimeImmutable('2026-11-01 01:30:00 UTC'));
        self::assertSame('new', $this->read('public/new.txt'));
        self::assertSame(ChainRestoreStep::SKIPPED, $report->steps[1]->status);
        self::assertStringContainsString('Files are restored as of', implode(' ', $report->notes));

        // Before the full backup: refused, nothing changed.
        try {
            $manager->restoreChain($i2->getId(), new DateTimeImmutable('2025-01-01 UTC'));
            self::fail('a time before the full backup was accepted');
        } catch (ChainBrokenException) {
        }
        self::assertSame('new', $this->read('public/new.txt'));

        // An incremental alone is never restored, a parent is never deleted before its children.
        try {
            $manager->restore($i2->getId());
            self::fail('a lone incremental was restored');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('restore its chain', $e->getMessage());
        }
        try {
            $manager->deleteBackup($i1->getId());
            self::fail('a parent was deleted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('parent of incremental', $e->getMessage());
        }
        $manager->deleteBackup($i2->getId());
        $manager->deleteBackup($i1->getId());
        $manager->deleteBackup($full->getId());
    }

    public function testChangedScopeTamperingAndMissingParents(): void
    {
        $manager = $this->manager();
        $full    = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));

        // Other include paths would read as mass deletions.
        $options = $manager->incrementalOptions($full->getId());
        try {
            $manager->createBackup(new BackupOptions(includeDatabase: false, includePaths: ['config'], baseManifest: $options->baseManifest, parentId: $full->getId(), chainId: $full->getId()));
            self::fail('a scope change was accepted');
        } catch (InvalidBackupOptionsException $e) {
            self::assertStringContainsString('take a full backup', $e->getMessage());
        }

        $this->clock->sleep(60);
        $this->put('config/app.yaml', 'changed');
        $inc = $manager->createBackup($options);

        // A restore verifies every archive before touching anything.
        file_put_contents($inc->getAbsolutePath(), 'tampered', FILE_APPEND);
        $this->put('config/app.yaml', 'live');
        try {
            $manager->restoreChain($inc->getId());
            self::fail('a tampered archive was restored');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('SHA-256 mismatch', $e->getMessage());
        }
        self::assertSame('live', $this->read('config/app.yaml'));

        // A missing parent breaks the chain.
        (new Filesystem())->remove([$full->getAbsolutePath(), $this->storage . '/' . $full->getId() . '.meta.json']);
        try {
            $manager->resolveChain($inc->getId());
            self::fail('a chain without its full backup resolved');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('missing from storage_dir', $e->getMessage());
        }
        try {
            $manager->incrementalOptions('nope');
            self::fail('an unknown parent was accepted');
        } catch (InvalidBackupOptionsException) {
        }
    }

    public function testDatabaseChainDumpCoordinatesCaptureAndReplay(): void
    {
        $tools    = $this->root . '/tools';
        $client   = BinlogFakes::mysqlClient($tools);
        $binlog   = BinlogFakes::mysqlbinlog($tools);
        $url      = 'mysql://bk@127.0.0.1:3306/app';
        $dumper   = new BuiltinMysqlDumper($url, $this->fakeDump());
        $capture  = $this->captureStub();
        $server   = BinlogFakes::server([['name' => 'binlog.000002', 'size' => 2000]]);
        $archiver = new BackupArchiver($this->project, $this->storage, ['config', 'public'], [], null, 60, clock: $this->clock, builtinDumper: $dumper, workDir: $this->root . '/work', binlogCapture: $capture, binlogServer: $server);
        $restorer = new ChainRestorer(
            $archiver,
            RestorePathGuard::fromArchiver($this->project, [], $archiver),
            $this->project,
            new DumpImporter($url, $client),
            new BinlogReplayer($url, new MysqlBinlogTool($binlog), $client, 30, $this->root . '/work'),
        );
        $manager = $this->manager($archiver, $restorer);

        $full = $manager->createBackup(new BackupOptions(recordBinlogCoordinates: true, recordFileManifest: true));
        self::assertSame('binlog.000002', $full->getMeta()['binlog_file']);
        self::assertSame(1480, $full->getMeta()['binlog_position']);
        self::assertSame('x:1-11', $full->getMeta()['gtid_executed']);
        self::assertSame(ChainFixtures::UUID, $full->getMeta()['server_uuid']);
        self::assertSame('app', $full->getMeta()['binlog_database']);
        self::assertTrue($full->getMeta()['dump']['source_data']);

        $this->clock->sleep(3600);
        $i1 = $manager->createBackup($manager->incrementalOptions($full->getId()));
        self::assertSame(['binlog.000002', 'binlog.000003'], array_column($i1->getMeta()['binlog_files'], 'name'));
        self::assertSame('binlog.000003', $i1->getMeta()['binlog_file']);
        self::assertSame(['binlog_file' => 'binlog.000002', 'binlog_position' => 1480, 'server_uuid' => ChainFixtures::UUID, 'gtid_executed' => 'x:1-11'], $i1->getMeta()['binlog_from']);
        self::assertArrayHasKey('.nowo-site-backup/binlog/binlog.000003', $i1->getChecksums());
        self::assertSame('database_files', $i1->getMeta()['content']);
        self::assertSame([], glob($this->root . '/captures/*') ?: [], 'the capture directory outlived the backup');

        $this->clock->sleep(3600);
        $i2 = $manager->createBackup($manager->incrementalOptions($i1->getId()));

        // The decoded chain: binlog.000002 from 1480, then binlog.000003 and binlog.000004 (900 bytes each).
        file_put_contents($tools . '/decoded.3', "DELIMITER /*!*/;\n" . FakeBinlog::start(127) . FakeBinlog::event(1480, 2000, "Query\tthread_id=1", "use `app`/*!*/;\nBEGIN\n/*!*/;\n")
            . FakeBinlog::start(4) . FakeBinlog::event(127, 900, 'Xid = 1', "COMMIT/*!*/;\n")
            . FakeBinlog::start(4) . FakeBinlog::event(127, 900, "Query\tthread_id=1", "use `app`/*!*/;\nCREATE TABLE later (id INT)\n/*!*/;\n", '261101  1:45:00') . "# End of log file\n");
        $report = $manager->restoreChain($i2->getId(), new DateTimeImmutable('2026-11-01 01:30:00 UTC'));
        self::assertTrue($report->complete);
        self::assertSame("import\nreplay\n", file_get_contents($tools . '/order'));
        self::assertStringContainsString('CREATE TABLE t', (string) file_get_contents($tools . '/imported.sql'));
        $decodeArgs = (string) file_get_contents($tools . '/binlog-args');
        // PP-D1-H1: the whole logs are decoded (their structure is checked), the filter cuts at the target instant.
        self::assertStringContainsString('--skip-gtids --start-position=1480 --result-file=', $decodeArgs);
        self::assertStringNotContainsString('--stop-datetime', $decodeArgs);
        $replayed = (string) file_get_contents($tools . '/replayed.sql');
        self::assertStringContainsString('BEGIN', $replayed);
        self::assertStringNotContainsString('CREATE TABLE later', $replayed, 'an event after the target instant was replayed');
        self::assertStringContainsString('.nowo-site-backup/binlog/binlog.000004', $decodeArgs);
        self::assertStringNotContainsString('--database', $decodeArgs);
        self::assertSame(
            ['database_dump:applied', 'binlog:applied', 'files:applied', 'files:skipped', 'deletions:applied'],
            array_map(static fn (ChainRestoreStep $s): string => $s->part . ':' . $s->status, $report->steps),
        );
        self::assertSame($i1->getId() . '..' . $i2->getId(), $report->steps[1]->backupId);
        self::assertSame(['binlog.000004'], array_column($i2->getMeta()['binlog_files'], 'name'));

        // A replay failure after the dump import: the report says exactly what the live database has.
        file_put_contents($tools . '/order', '');
        file_put_contents($tools . '/decoded', "BINLOG 'x';\n");
        EngineFixtures::script($tools, 'fake-mysql-broken', 'if [ "$1" = "--version" ]; then echo "mysql  Ver 9.7.2 for Linux"; exit 0; fi; case "$*" in *--binary-mode*) echo "ERROR 1062 (23000) at line 9: Duplicate entry \'secret-row\'" >&2; exit 1;; esac; cat > /dev/null; exit 0');
        $broken = new ChainRestorer(
            $archiver,
            RestorePathGuard::fromArchiver($this->project, [], $archiver),
            $this->project,
            new DumpImporter($url, $tools . '/fake-mysql-broken'),
            new BinlogReplayer($url, new MysqlBinlogTool($binlog), $tools . '/fake-mysql-broken', 30, $this->root . '/work'),
        );
        try {
            $this->manager($archiver, $broken)->restoreChain($i2->getId());
            self::fail('a failed replay was reported as success');
        } catch (ChainRestoreException $e) {
            $status = array_map(static fn (ChainRestoreStep $s): string => $s->part . ':' . $s->status, $e->getReport()->steps);
            self::assertSame(['database_dump:applied', 'binlog:failed', 'files:not_applied', 'deletions:not_applied'], $status);
            self::assertStringNotContainsString('secret-row', $e->getMessage());
            self::assertStringContainsString('ERROR 1062 (23000) at line 9', (string) $e->getReport()->error);
        }

        // Another configured database: refused before anything is applied.
        $elsewhere = new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project, new DumpImporter($url, $client), new BinlogReplayer('mysql://bk@127.0.0.1/other', new MysqlBinlogTool($binlog), $client));
        try {
            $this->manager($archiver, $elsewhere)->restoreChain($i2->getId());
            self::fail('a replay into another database was accepted');
        } catch (ChainBrokenException $e) {
            self::assertStringContainsString('configured database differs', $e->getMessage());
        }
        // A decode failure is still "nothing changed".
        file_put_contents($tools . '/decode-error', '1');
        file_put_contents($tools . '/order', '');
        try {
            $manager->restoreChain($i2->getId());
            self::fail('a decode failure was accepted');
        } catch (ChainBrokenException) {
        }
        self::assertSame('', file_get_contents($tools . '/order'));
    }

    public function testCoordinatesNeedTheOracleToolsAndTheBuiltinDumper(): void
    {
        $plain = new BackupArchiver($this->project, $this->storage, ['config'], [], 'echo', 60, clock: $this->clock);
        foreach ([new BackupOptions(recordBinlogCoordinates: true), new BackupOptions(includeFiles: false, binlogFrom: new BinlogCoordinates('binlog.000001', 4, ChainFixtures::UUID), parentId: 'p', chainId: 'p')] as $options) {
            try {
                $plain->create($options);
                self::fail('chain options without the builtin dumper were accepted');
            } catch (InvalidBackupOptionsException) {
            }
        }

        $maria = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, builtinDumper: new BuiltinMysqlDumper('mysql://bk@db/app', EngineFixtures::fakeMysqldump($this->root, 'mysqldump from 11.8.2-MariaDB, client 10.19')), binlogServer: BinlogFakes::server([]));
        try {
            $maria->create(new BackupOptions(recordBinlogCoordinates: true));
            self::fail('the MariaDB client recorded coordinates');
        } catch (InvalidBackupOptionsException $e) {
            self::assertStringContainsString('Oracle mysqldump', $e->getMessage());
        }

        // log_bin off: mysqldump writes no coordinates.
        $off = new BackupArchiver($this->project, $this->storage, ['config'], [], null, 60, clock: $this->clock, builtinDumper: new BuiltinMysqlDumper('mysql://bk@db/app', EngineFixtures::fakeMysqldump($this->root)), binlogServer: BinlogFakes::server([]));
        try {
            $off->create(new BackupOptions(recordBinlogCoordinates: true));
            self::fail('a dump without coordinates was accepted');
        } catch (DumpFailedException $e) {
            self::assertStringContainsString('no binlog coordinates', $e->getMessage());
        }
        self::assertSame([], glob($this->storage . '/*.tar.gz') ?: []);
    }

    public function testChainImportKeepsTheChainAndRollsBack(): void
    {
        $source = $this->manager();
        $full   = $source->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $this->clock->sleep(60);
        $this->put('config/app.yaml', 'v2');
        $inc = $source->createBackup($source->incrementalOptions($full->getId()));

        $this->storage = $this->root . '/elsewhere';
        $target        = $this->manager();

        try {
            $target->importArchive($inc->getAbsolutePath());
            self::fail('an incremental was imported alone');
        } catch (InvalidBackupOptionsException) {
        }
        try {
            $target->importChain([$inc->getAbsolutePath(), $full->getAbsolutePath()]);
            self::fail('a chain in the wrong order was imported');
        } catch (InvalidBackupOptionsException) {
        }
        try {
            $target->importChain([$full->getAbsolutePath(), $full->getAbsolutePath()]);
            self::fail('a full backup continued a chain');
        } catch (CorruptArchiveException) {
        }
        self::assertSame([], $target->listBackups(), 'a failed chain import left archives behind');
        try {
            $target->importChain([]);
            self::fail('an empty chain was accepted');
        } catch (InvalidBackupOptionsException) {
        }

        $head  = $target->importArchive([$full->getAbsolutePath(), $inc->getAbsolutePath()], null, 'ops', new ImportOptions(deleteAfterRestore: true));
        $chain = $target->resolveChain($head->getId());
        self::assertCount(2, $chain->artifacts);
        self::assertSame($chain->root()->getId(), $head->getMeta()['parent_id']);
        self::assertSame($full->getId(), $head->getMeta()['chain_id']);
        self::assertSame($full->getMeta()['created_at'], $chain->links[0]->createdAt->format(DATE_ATOM));

        $this->put('config/app.yaml', 'live');
        self::assertTrue($target->restoreChain($head->getId())->complete);
        self::assertSame('v2', $this->read('config/app.yaml'));
        self::assertSame([], $target->listBackups(), 'imported plaintext copies outlived the restore');
    }

    public function testLocalRetentionPrunesWholeChainsNewestFirst(): void
    {
        $manager = $this->manager(retention: new RetentionPolicy(keepLast: 1));
        $a       = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        $this->clock->sleep(60);
        $a1 = $manager->createBackup($manager->incrementalOptions($a->getId()));
        $this->clock->sleep(60);
        self::assertCount(2, $manager->listBackups(), 'the chain of the newest backup was pruned');

        $b = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));
        self::assertSame([$b->getId()], array_map(static fn (BackupArtifact $x): string => $x->getId(), $manager->listBackups()));
        self::assertNotSame($a1->getId(), $b->getId());
    }

    public function testRestorerEdgeCases(): void
    {
        $archiver = new BackupArchiver($this->project, $this->storage, ['config', 'public'], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');
        $manager  = $this->manager($archiver);
        $full     = $manager->createBackup(new BackupOptions(includeDatabase: false, recordFileManifest: true));

        // Without a restorer service.
        $bare = new SiteBackupManager($archiver, new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage(), clock: $this->clock);
        try {
            $bare->restoreChain($full->getId());
            self::fail('a chain restore ran without a restorer');
        } catch (RuntimeException) {
        }

        // Protected and secret paths are never written; a directory in the way is reported.
        $guarded = new ChainRestorer($archiver, new RestorePathGuard($this->project, ['config/app.yaml'], [], new SecretPathPolicy()), $this->project);
        $this->put('config/app.yaml', 'live');
        (new Filesystem())->remove($this->project . '/public/index.php');
        (new Filesystem())->mkdir($this->project . '/public/index.php');
        $report = $guarded->restore($manager->resolveChain($full->getId()));
        self::assertSame('live', $this->read('config/app.yaml'));
        self::assertStringContainsString('config/app.yaml (protected path)', $report->steps[0]->detail);
        self::assertStringContainsString('public/index.php (a link or directory is in the way)', $report->steps[0]->detail);

        $guard = new RestorePathGuard($this->project, ['/var/data/'], ['var/state.json'], new SecretPathPolicy());
        self::assertSame('unsafe path', $guard->refusal('../etc/passwd'));
        self::assertSame('protected path', $guard->refusal('var/data/x'));
        self::assertSame('runtime state', $guard->refusal('var/site-backup/x'));
        self::assertSame('runtime state', $guard->refusal('var/state.json'));
        self::assertSame('secret path', $guard->refusal('.env'));
        self::assertNull($guard->refusal('public/x'));
        self::assertTrue($guard->parentStaysInside('deep/new/dir/file'));
        symlink($this->root, $this->project . '/escape');
        self::assertFalse($guard->parentStaysInside('escape/file'));
        self::assertFalse((new RestorePathGuard($this->root . '/missing', [], [], new SecretPathPolicy()))->parentStaysInside('x'));
    }

    private function captureStub(): BinlogCaptureInterface
    {
        $dir = $this->root . '/captures';
        (new Filesystem())->mkdir($dir);

        return new class($dir) implements BinlogCaptureInterface {
            public function __construct(private readonly string $dir)
            {
            }

            public function capture(BinlogCoordinates $from, string $database, ?callable $tick = null): IncrementalArtifact
            {
                $work  = $this->dir . '/' . $from->sequence();
                $next  = 'binlog.' . sprintf('%06d', $from->sequence() + 1);
                $files = [];
                (new Filesystem())->mkdir($work);
                // binlog.000002 (the dump's file) is 2000 bytes, later ones 900: a log read to its end is not read again.
                $logs = $from->position < ($from->sequence() === 2 ? 2000 : 900) ? [$from->file => $from->sequence() === 2 ? 2000 : 900] : [];
                foreach ($logs + [$next => 900] as $name => $size) {
                    FakeBinlog::write($work . '/' . $name, $size === 2000 ? [127, 1480, 2000] : [127, $size]);
                    $files[] = new CapturedBinlog($name, $work . '/' . $name, $size, (string) hash_file('sha256', $work . '/' . $name));
                }

                return new IncrementalArtifact($from, new BinlogCoordinates($next, 900, $from->serverUuid), $database, $files, $work, new DateTimeImmutable('2026-11-01 00:10 UTC'), new DateTimeImmutable('2026-11-01 00:50 UTC'), new DateTimeImmutable('2026-11-01 01:00 UTC'));
            }

            public function discard(IncrementalArtifact $artifact): void
            {
                (new Filesystem())->remove($artifact->directory);
            }
        };
    }

    private function fakeDump(): string
    {
        return EngineFixtures::script($this->root . '/tools', 'fake-dump', <<<'SH'
            if [ "$1" = "--version" ]; then echo "mysqldump  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            out=""
            for a in "$@"; do case "$a" in --result-file=*) out="${a#--result-file=}";; esac; done
            exec 1>"$out"
            echo "-- args: $*"
            echo "/* SET @@GLOBAL.GTID_PURGED='+x:1-11';*/"
            echo "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;"
            echo "CREATE TABLE t (id INT);"
            SH);
    }

    private function manager(?BackupArchiver $archiver = null, ?ChainRestorer $restorer = null, ?RetentionPolicy $retention = null): SiteBackupManager
    {
        $archiver ??= new BackupArchiver($this->project, $this->storage, ['config', 'public'], [], null, 60, clock: $this->clock, workDir: $this->root . '/work');
        $restorer ??= new ChainRestorer($archiver, RestorePathGuard::fromArchiver($this->project, [], $archiver), $this->project);

        return new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: $this->history,
            clock: $this->clock,
            logger: new RecordingLogger(),
            localRetention: $retention,
            chainRestorer: $restorer,
        );
    }

    /**
     * @param list<string> $expected
     */
    private function assertEntries(array $expected, BackupArtifact $artifact): void
    {
        $entries = $artifact->getMeta()['file_manifest']['entries'] ?? null;
        self::assertTrue(is_array($entries));
        self::assertSame($expected, array_keys($entries));
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    private function sorted(array $list): array
    {
        sort($list);

        return $list;
    }

    private function put(string $relative, string $content): void
    {
        (new Filesystem())->mkdir(dirname($this->project . '/' . $relative));
        file_put_contents($this->project . '/' . $relative, $content);
        // Distinct mtimes before the (mock) backup time: unchanged files are trusted by the next delta.
        touch($this->project . '/' . $relative, $this->mtime());
    }

    private function mtime(): int
    {
        return $this->clock->now()->getTimestamp() - 1000 + ++$this->tick;
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->project . '/' . $relative);
    }
}
