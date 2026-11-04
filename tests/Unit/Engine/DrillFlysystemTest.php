<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use InvalidArgumentException;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Command\RestoreDrillCommand;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\TableStatsCollector;
use Nowo\SiteBackupBundle\Drill\RestoreDrill;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Remote\FlysystemRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Remote\RemoteObject;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function json_decode;
use function stream_get_contents;

final class DrillFlysystemTest extends TestCase
{
    private const URL = 'mysql://app:pw@db/beacon';

    private string $root;
    private string $bin;

    protected function setUp(): void
    {
        $this->root = EngineFixtures::tempDir('sbb-drill-');
        $this->bin  = $this->root . '/bin';
        (new Filesystem())->mkdir([$this->bin, $this->root . '/project/config', $this->root . '/stats']);
        file_put_contents($this->root . '/project/config/app.yaml', 'a: b');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testCollectorParsesCountsAndChecksums(): void
    {
        $this->stats('beacon', ['user' => [3, '111'], 'post' => [5, '222']]);

        self::assertSame([
            'post' => ['rows' => 5, 'checksum' => '222'],
            'user' => ['rows' => 3, 'checksum' => '111'],
        ], $this->collector()->collect());
        self::assertSame([], $this->collector()->collect('empty_schema'));
    }

    public function testDrillPassesFailsAndAlwaysDropsItsSchema(): void
    {
        $this->stats('beacon', ['user' => [3, '111'], 'event_log' => [9, '999']]);
        $archiver = $this->archiver();
        $artifact = $archiver->create(new BackupOptions(includeFiles: false, recordTableStats: true));
        self::assertSame(['event_log' => ['rows' => 9, 'checksum' => '999'], 'user' => ['rows' => 3, 'checksum' => '111']], $artifact->getMeta()['table_stats']);

        $drill = new RestoreDrill($archiver, $this->importer(), $this->collector(), $this->root . '/work');

        // table_stats survive an import on another instance.
        $copy = (new BackupArchiver($this->root . '/project', $this->root . '/copy', ['config'], [], null, 60))->import($artifact->getAbsolutePath());
        self::assertSame($artifact->getMeta()['table_stats'], $copy->getMeta()['table_stats']);

        $this->stats('drill_1', ['user' => [3, '111'], 'event_log' => [9, '999']]);
        $report = $drill->run($artifact, 'drill_1');
        self::assertTrue($report->passed);
        self::assertSame(2, $report->tablesChecked);
        self::assertSame("CREATE DATABASE `drill_1`\nDROP DATABASE IF EXISTS `drill_1`\n", file_get_contents($this->root . '/statements'));
        self::assertStringContainsString('-- args:', (string) file_get_contents($this->root . '/imported-drill_1.sql'));

        $this->stats('drill_2', ['user' => [2, '110']]);
        $failed = $drill->run($artifact, 'drill_2');
        self::assertFalse($failed->passed);
        self::assertSame(['event_log', 'user'], array_column($failed->mismatches, 'table'));
        self::assertTrue($failed->toArray()['tables_checked'] === 2);

        // Tolerance accepts small row differences and ignores checksums.
        self::assertSame([], RestoreDrill::compare(['t' => ['rows' => 10, 'checksum' => 'a']], ['t' => ['rows' => 11, 'checksum' => 'b']], 1));
        self::assertCount(1, RestoreDrill::compare(['t' => ['rows' => 10, 'checksum' => 'a']], ['t' => ['rows' => 10, 'checksum' => 'b']]));
        self::assertSame([], RestoreDrill::compare(['t' => ['rows' => 0, 'checksum' => null]], ['t' => ['rows' => 0, 'checksum' => '0']]));
    }

    public function testDrillGuards(): void
    {
        $archiver = $this->archiver();
        $plain    = $archiver->create(new BackupOptions(includeFiles: false));
        $drill    = new RestoreDrill($archiver, $this->importer(), $this->collector());

        foreach (['beacon', 'BEACON', 'bad-name', ''] as $schema) {
            try {
                $drill->run($plain, $schema);
                self::fail('Expected InvalidArgumentException for ' . $schema);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no table statistics');
        $drill->run($plain, 'drill_x');
    }

    public function testFailedCreateNeverDropsAndFailedImportStillDrops(): void
    {
        $this->stats('beacon', ['user' => [1, '1']]);
        $archiver = $this->archiver();
        $artifact = $archiver->create(new BackupOptions(includeFiles: false, recordTableStats: true));

        file_put_contents($this->root . '/fail-create', '1');
        try {
            (new RestoreDrill($archiver, $this->importer(), $this->collector()))->run($artifact, 'drill_exists');
            self::fail('Expected DumpFailedException');
        } catch (DumpFailedException) {
        }
        self::assertStringNotContainsString('DROP', (string) @file_get_contents($this->root . '/statements'));

        unlink($this->root . '/fail-create');
        file_put_contents($this->root . '/fail-import', '1');
        try {
            (new RestoreDrill($archiver, $this->importer(), $this->collector()))->run($artifact, 'drill_y');
            self::fail('Expected DumpFailedException');
        } catch (DumpFailedException) {
        }
        self::assertStringContainsString('DROP DATABASE IF EXISTS `drill_y`', (string) file_get_contents($this->root . '/statements'));
    }

    public function testRecordTableStatsNeedsTheCollectorAndHonoursRules(): void
    {
        $this->expectException(InvalidBackupOptionsException::class);
        (new BackupArchiver($this->root . '/project', $this->root . '/s2', ['config'], [], 'echo x', 60))->create(new BackupOptions(recordTableStats: true));
    }

    public function testExpectedStatsFollowTableRules(): void
    {
        self::assertSame(
            ['a' => ['rows' => 5, 'checksum' => '1'], 'b' => ['rows' => 0, 'checksum' => '0']],
            BackupArchiver::expectedTableStats(['a' => ['rows' => 5, 'checksum' => '1'], 'b' => ['rows' => 7, 'checksum' => '2'], 'c' => ['rows' => 1, 'checksum' => '3']], ['b' => TableRule::StructureOnly->value, 'c' => TableRule::Skip->value]),
        );
    }

    public function testDrillCommand(): void
    {
        $this->stats('beacon', ['user' => [3, '111']]);
        $archiver = $this->archiver();
        $artifact = $archiver->create(new BackupOptions(includeFiles: false, recordTableStats: true));
        $manager  = new SiteBackupManager($archiver, new RestoreOrchestrator($this->root . '/project', $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage());
        $tester   = new CommandTester(new RestoreDrillCommand($manager, new RestoreDrill($archiver, $this->importer(), $this->collector())));

        $this->stats('drill_ok', ['user' => [3, '111']]);
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $artifact->getId(), '--schema' => 'drill_ok']));
        self::assertStringContainsString('Drill passed', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $artifact->getId(), '--schema' => 'drill_ok', '--json' => true]));
        self::assertTrue(json_decode($tester->getDisplay(), true)['passed'] ?? false);

        $this->stats('drill_bad', ['user' => [4, '112']]);
        self::assertSame(Command::FAILURE, $tester->execute(['id' => $artifact->getId(), '--schema' => 'drill_bad']));
        self::assertStringContainsString('user: rows 3 / 4', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['id' => 'nope']));
        self::assertSame(2, $tester->execute(['id' => $artifact->getId(), '--row-tolerance' => '-1']));
        self::assertSame(2, $tester->execute(['id' => $artifact->getId(), '--schema' => 'beacon']));
    }

    public function testFlysystemTargetWithThePublisher(): void
    {
        $fs        = new Flysystem(new InMemoryFilesystemAdapter());
        $target    = new FlysystemRemoteTarget($fs);
        $clock     = new MockClock('2026-11-04 03:00:00');
        $archiver  = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60, clock: $clock);
        $publisher = new RemoteBackupPublisher(null, $clock);
        $ids       = [];
        for ($i = 0; $i < 3; ++$i) {
            $artifact = $archiver->create();
            $publisher->publish($artifact, $target, 'backups/', 'backups/' . $artifact->getId() . '.tar.gz');
            $ids[] = $artifact->getId();
            $clock->sleep(3600);
        }

        self::assertNull($target->head('backups/missing'));
        $keys = array_map(static fn (RemoteObject $o) => $o->key, iterator_to_array($target->list('backups/'), false));
        self::assertCount(6, $keys);
        $sidecar = json_decode((string) stream_get_contents($target->openRead('backups/' . $ids[0] . '.tar.gz.backup.json')), true);
        self::assertSame($ids[0], $sidecar['id'] ?? null);

        $report = $publisher->prune($target, 'backups/', new RetentionPolicy(keepLast: 1), $ids[2]);
        self::assertCount(2, $report->deletedKeys);
        self::assertCount(2, iterator_to_array($target->list(''), false));
        $target->delete('backups/does-not-exist');
    }

    private function archiver(): BackupArchiver
    {
        return new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], 'echo "-- args: dump"', 60, workDir: $this->root . '/work', statsCollector: $this->collector());
    }

    private function collector(): TableStatsCollector
    {
        return new TableStatsCollector(self::URL, $this->client());
    }

    private function importer(): DumpImporter
    {
        return new DumpImporter(self::URL, $this->client());
    }

    /**
     * @param array<string, array{0: int, 1: string}> $tables
     */
    private function stats(string $schema, array $tables): void
    {
        $names = $counts = $checksums = '';
        foreach ($tables as $table => [$rows, $checksum]) {
            $names .= $table . "\n";
            $counts .= $table . "\t" . $rows . "\n";
            $checksums .= $schema . '.' . $table . "\t" . $checksum . "\n";
        }
        file_put_contents($this->root . '/stats/' . $schema . '.names', $names);
        file_put_contents($this->root . '/stats/' . $schema . '.counts', $counts);
        file_put_contents($this->root . '/stats/' . $schema . '.checksums', $checksums);
    }

    /**
     * A mysql client stand-in: schema statements are logged, imports are saved per schema, statistics come from
     * the files written by {@see self::stats()}.
     */
    private function client(): string
    {
        $root = $this->root;

        return EngineFixtures::client($this->bin, 'fake-mysql', <<<SH
            db=""; sql=""
            for a in "\$@"; do case "\$a" in --execute=*) sql="\${a#--execute=}";; esac; db="\$a"; done
            case "\$sql" in
              "CREATE DATABASE"*) if [ -f "{$root}/fail-create" ]; then echo "ERROR 1007 (HY000) at line 1: database exists" >&2; exit 1; fi; echo "\$sql" >> "{$root}/statements";;
              "DROP DATABASE"*) echo "\$sql" >> "{$root}/statements";;
              *information_schema*) cat "{$root}/stats/\$db.names" 2>/dev/null;;
              *"UNION ALL"*|"SELECT '"*) sort "{$root}/stats/\$db.counts" 2>/dev/null;;
              "CHECKSUM TABLE"*) cat "{$root}/stats/\$db.checksums" 2>/dev/null;;
              "") if [ -f "{$root}/fail-import" ]; then cat >/dev/null; echo "ERROR 1064 (42000) at line 1: x" >&2; exit 1; fi; cat > "{$root}/imported-\$db.sql";;
            esac
            exit 0
            SH);
    }
}
