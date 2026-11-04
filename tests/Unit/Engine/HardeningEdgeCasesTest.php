<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\TableStatsCollector;
use Nowo\SiteBackupBundle\Drill\RestoreDrill;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function json_encode;

final class HardeningEdgeCasesTest extends TestCase
{
    private string $root;
    private string $project;
    private string $storage;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-hard-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        (new Filesystem())->mkdir([$this->project . '/public', $this->storage, $this->root . '/bin']);
        file_put_contents($this->project . '/public/index.php', '<?php');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testImportTicksAndUnsafeTarNamesOnVerify(): void
    {
        $client = EngineFixtures::client($this->root . '/bin', 'mysql-tick', 'cat >/dev/null; sleep 1');
        file_put_contents($this->root . '/in.sql', 'SELECT 1;');
        $ticks = 0;
        (new DumpImporter('mysql://app:pw@db/beacon', $client))->import($this->root . '/in.sql', static function () use (&$ticks): void {
            ++$ticks;
        });
        self::assertGreaterThan(0, $ticks);

        $archiver = new BackupArchiver($this->project, $this->storage, ['public'], [], null, 60);
        $artifact = $archiver->create();
        $escape   = EngineFixtures::script($this->root . '/bin', 'tar-escape', 'case "$1" in -tvzf) echo "-rw-r--r-- u/g 1 2026-01-01 00:00 ./a";; -tzf) echo "./../evil";; esac');
        $result   = (new BackupArchiver($this->project, $this->storage, ['public'], [], null, 60, tarBinary: $escape))->verifyIntegrity($artifact);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('Unsafe archive entry name', implode(' ', $result['errors']));
    }

    public function testDeletesOutsideStorageFailLoudlyAndRetentionLogsThem(): void
    {
        $clock    = new MockClock('2026-11-04 10:00:00');
        $archiver = new BackupArchiver($this->project, $this->storage, ['public'], [], null, 60, clock: $clock);
        $logger   = new RecordingLogger();
        $manager  = new SiteBackupManager(
            $archiver,
            new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            new MemoryHistoryStorage(),
            clock: $clock,
            logger: $logger,
            localRetention: new RetentionPolicy(keepLast: 0),
        );

        file_put_contents($this->root . '/victim.txt', 'keep me');
        $planted = new BackupArtifact('20000101-000000-planted', 'p.tar.gz', $this->root . '/victim.txt', new DateTimeImmutable('2000-01-01'), 1, 'x');
        file_put_contents($this->storage . '/20000101-000000-planted.meta.json', (string) json_encode($planted->toArray()));

        $manager->createBackup();
        self::assertFileExists($this->root . '/victim.txt');
        self::assertContains('warning SiteBackup: retention delete failed.', $logger->lines());

        $this->expectException(RuntimeException::class);
        $manager->deleteBackup('20000101-000000-planted');
    }

    public function testDrillRefusesABackupWithoutDump(): void
    {
        $archiver  = new BackupArchiver($this->project, $this->storage, ['public'], [], null, 60);
        $files     = $archiver->create();
        $meta      = $files->getMeta() + ['table_stats' => ['t' => ['rows' => 1, 'checksum' => '1']]];
        $withStats = new BackupArtifact($files->getId(), $files->getFilename(), $files->getAbsolutePath(), $files->getCreatedAt(), $files->getSizeBytes(), $files->getArchiveSha256(), $files->getChecksums(), $meta);
        $client    = EngineFixtures::client($this->root . '/bin', 'mysql-drill', 'exit 0');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no database dump');
        (new RestoreDrill($archiver, new DumpImporter('mysql://app:pw@db/beacon', $client), new TableStatsCollector('mysql://app:pw@db/beacon', $client)))->run($withStats, 'drill_x');
    }
}
