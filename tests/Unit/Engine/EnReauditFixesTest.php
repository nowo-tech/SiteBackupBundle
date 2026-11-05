<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function file_get_contents;
use function file_put_contents;
use function gzencode;
use function hash_file;
use function implode;
use function ord;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use function touch;
use function usleep;

/**
 * Fixes for the re-audit of `ec42e98` (appendix EN, "1.28.0 delta"). Canary values only.
 */
final class EnReauditFixesTest extends TestCase
{
    private string $root;
    private string $project;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-en2-');
        $this->project = $this->root . '/project';
        (new Filesystem())->mkdir([$this->project . '/public', $this->root . '/storage']);
        file_put_contents($this->project . '/.env.local', 'APP_SECRET=CANARY-env-local');
        file_put_contents($this->project . '/public/index.php', '<?php');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * EN-L6: a path flipping between a regular file, a symlink to .env.local and a hard link to it never ships the
     * secret (the re-audit saw 31 leaks in 668 backups before the fix).
     */
    public function testSwappingAFileForASecretDuringTheBackupNeverLeaksIt(): void
    {
        // The flipper stops itself when `stop` appears, so no child is still writing while tearDown() cleans up.
        $flipper = new Process(['sh', '-c', 'cd "$0" && while [ ! -f "$1" ]; do echo plain > f.tmp; mv -f f.tmp race.txt; ln -sf ../.env.local l.tmp; mv -f l.tmp race.txt; ln -f ../.env.local h.tmp 2>/dev/null && mv -f h.tmp race.txt; done', $this->project . '/public', $this->root . '/stop']);
        $flipper->start();
        $archiver = new BackupArchiver($this->project, $this->root . '/storage', ['public'], [], null, 60);

        try {
            for ($i = 0; $i < 150; ++$i) {
                $artifact = $archiver->create(new BackupOptions(includeDatabase: false));
                $extract  = $this->root . '/x' . $i;
                $archiver->extractTo($artifact, $extract);
                $race = $extract . '/public/race.txt';
                if (is_file($race)) {
                    self::assertStringNotContainsString('CANARY', (string) file_get_contents($race), 'run ' . $i);
                }
                (new Filesystem())->remove([$extract, $artifact->getAbsolutePath()]);
            }
        } finally {
            touch($this->root . '/stop');
            for ($wait = 0; $flipper->isRunning() && $wait < 500; ++$wait) {
                usleep(10_000);
            }
            $flipper->stop(0);
        }
    }

    /**
     * EN-L7: an owner name with spaces cannot hide the size of an entry.
     */
    public function testOwnerNamesWithSpacesDoNotBypassTheSizeLimit(): void
    {
        $tar = $this->ustar('MANIFEST.json', '{"checksums":{}}', 'a 1 b')
            . $this->ustar('big.bin', str_repeat("\0", 300_000), 'a 1 b')
            . str_repeat("\0", 1024);
        $path = $this->root . '/storage/spaced.tar.gz';
        file_put_contents($path, (string) gzencode($tar, 9));
        $artifact = new BackupArtifact('spaced', 'spaced.tar.gz', $path, new DateTimeImmutable(), 1, (string) hash_file('sha256', $path));

        $small  = new BackupArchiver($this->project, $this->root . '/storage', ['public'], [], null, 60, extractLimits: ['max_bytes' => 100_000]);
        $errors = implode(' ', $small->verifyIntegrity($artifact)['errors']);
        self::assertStringContainsString('backup.extract_limits', $errors);
    }

    /**
     * EO-L2: bsdtar's listing layout (and owner names with spaces) cannot hide the size of an entry either.
     */
    public function testBsdtarListingsDoNotBypassTheSizeLimit(): void
    {
        $bsdtar = (new ExecutableFinder())->find('bsdtar');
        if ($bsdtar === null) {
            self::markTestSkipped('bsdtar (libarchive-tools) is not installed.');
        }

        foreach (['plain' => 'app', 'spaced' => 'a 1 b'] as $name => $owner) {
            $tar = $this->ustar('MANIFEST.json', '{"checksums":{}}', $owner)
                . $this->ustar('big.bin', str_repeat("\0", 300_000), $owner)
                . str_repeat("\0", 1024);
            $path = $this->root . '/storage/bsd-' . $name . '.tar.gz';
            file_put_contents($path, (string) gzencode($tar, 9));
            $artifact = new BackupArtifact('bsd-' . $name, 'bsd-' . $name . '.tar.gz', $path, new DateTimeImmutable(), 1, (string) hash_file('sha256', $path));

            $small  = new BackupArchiver($this->project, $this->root . '/storage', ['public'], [], null, 60, tarBinary: $bsdtar, extractLimits: ['max_bytes' => 100_000]);
            $errors = implode(' ', $small->verifyIntegrity($artifact)['errors']);
            self::assertStringContainsString('backup.extract_limits', $errors, $name);
        }
    }

    public function testPrefixSpellingsOfRefusedOptionsAreRefused(): void
    {
        foreach (['--pass=x', '--res=/tmp/x', '--defaults-ex=/x', '--datab'] as $bad) {
            try {
                BuiltinMysqlDumper::assertExtraOptions([$bad]);
                self::fail('Expected refusal of ' . $bad);
            } catch (InvalidBackupOptionsException) {
                self::addToAssertionCount(1);
            }
        }
        BuiltinMysqlDumper::assertExtraOptions(['--column-statistics=0', '--skip-comments']);
    }

    /**
     * EN-I12: warnings (here: a skipped secret) are logged at notice level.
     */
    public function testWarningsAreLoggedAtNotice(): void
    {
        $archiver = new BackupArchiver($this->project, $this->root . '/storage', ['public', '.env.local'], [], null, 60);
        $logger   = new RecordingLogger();
        (new SiteBackupManager($archiver, new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage(), logger: $logger))->createBackup();

        self::assertContains('notice SiteBackup: backup warning.', $logger->lines());
    }

    public function testImportTicksAndExtraMetaAndSqlGzTicks(): void
    {
        $client = EngineFixtures::client($this->root, 'mysql-tick', 'cat >/dev/null; sleep 1');
        file_put_contents($this->root . '/in.sql', 'SELECT 1;');
        $ticks = 0;
        (new DumpImporter('mysql://app:pw@db/beacon', $client))->import($this->root . '/in.sql', static function () use (&$ticks): void {
            ++$ticks;
        });
        self::assertGreaterThan(0, $ticks);

        $archiver = new BackupArchiver($this->project, $this->root . '/storage', ['public'], [], 'echo "SELECT 1;"', 60);
        $sql      = $archiver->create(new BackupOptions(includeFiles: false, format: ArchiveFormat::SqlGz, extraMeta: ['plan' => 'p1']));
        self::assertSame(['plan' => 'p1'], $sql->getMeta()['extra']);
        $beats = 0;
        self::assertTrue($archiver->verifyIntegrity($sql, static function () use (&$beats): void {
            ++$beats;
        })['ok']);
        self::assertGreaterThan(0, $beats);
    }

    private function ustar(string $name, string $data, string $owner): string
    {
        $header = str_pad($name, 100, "\0")
            . str_pad('0000644', 8, "\0")
            . str_pad('0001750', 8, "\0")
            . str_pad('0001750', 8, "\0")
            . str_pad(sprintf('%011o', strlen($data)), 12, "\0")
            . str_pad(sprintf('%011o', 1_700_000_000), 12, "\0")
            . '        '
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_pad($owner, 32, "\0")
            . str_pad($owner, 32, "\0")
            . str_repeat("\0", 183);
        $sum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $sum += ord($header[$i]);
        }
        $header = substr($header, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($header, 156);

        return $header . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }
}
