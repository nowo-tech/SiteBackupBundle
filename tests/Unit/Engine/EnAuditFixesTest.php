<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Command\DatabaseImportCommand;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

use function file_put_contents;
use function gethostname;
use function gzencode;
use function hash_file;
use function json_encode;
use function link;
use function mkdir;
use function str_repeat;
use function symlink;

/**
 * Fixes for the REQ-SEC-004 audit of 1.28.0 (appendix EN). Canary values only.
 */
final class EnAuditFixesTest extends TestCase
{
    private string $root;
    private string $project;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-en-');
        $this->project = $this->root . '/project';
        (new Filesystem())->mkdir([$this->project . '/config/secrets/prod', $this->project . '/public', $this->root . '/bin', $this->root . '/outside']);
        file_put_contents($this->project . '/config/secrets/prod/prod.decrypt.private.php', 'CANARY-vault-key');
        file_put_contents($this->project . '/.env.local', 'APP_SECRET=CANARY-env-local');
        file_put_contents($this->project . '/public/index.php', '<?php');
        file_put_contents($this->root . '/outside/db-credentials.txt', 'CANARY-outside');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function clientVersions(): iterable
    {
        yield 'oracle 8.4.13' => ['mysql  Ver 8.4.13 for Linux on x86_64 (MySQL Community Server - GPL)', '--system-command=OFF'];
        yield 'oracle 8.4.3' => ['mysql  Ver 8.4.3 for Linux on x86_64', '--system-command=OFF'];
        yield 'oracle 8.4.2' => ['mysql  Ver 8.4.2 for Linux on x86_64', null];
        yield 'oracle 8.0.40' => ['mysql  Ver 8.0.40 for Linux on x86_64', '--system-command=OFF'];
        yield 'oracle 8.0.39' => ['mysql  Ver 8.0.39 for Linux on x86_64', null];
        yield 'oracle 8.3.0' => ['mysql  Ver 8.3.0 for Linux on x86_64', null];
        yield 'oracle 9.0.1' => ['mysql  Ver 9.0.1 for Linux on x86_64', null];
        yield 'oracle 9.1.0' => ['mysql  Ver 9.1.0 for Linux on x86_64', '--system-command=OFF'];
        yield 'oracle 9.4.0' => ['mysql  Ver 9.4.0 for Linux on x86_64', '--system-command=OFF'];
        yield 'oracle 5.7' => ['mysql  Ver 14.14 Distrib 5.7.44, for Linux (x86_64)', null];
        yield 'mariadb 11.8.9' => ['mariadb from 11.8.9-MariaDB, client 15.2 for debian-linux-gnu (x86_64)', '--sandbox'];
        yield 'mariadb 11.4.2' => ['mariadb from 11.4.2-MariaDB, client 15.2', '--sandbox'];
        yield 'mariadb 11.4.1' => ['mariadb from 11.4.1-MariaDB, client 15.2', null];
        yield 'mariadb 10.11.6 old format' => ['mysql  Ver 15.1 Distrib 10.11.6-MariaDB, for debian-linux-gnu', null];
        yield 'mariadb 10.11.8' => ['mysql  Ver 15.1 Distrib 10.11.8-MariaDB, for debian-linux-gnu', '--sandbox'];
        yield 'mariadb 10.6.18' => ['mysql  Ver 15.1 Distrib 10.6.18-MariaDB', '--sandbox'];
        yield 'mariadb 10.9.8' => ['mysql  Ver 15.1 Distrib 10.9.8-MariaDB', null];
        yield 'mariadb unparsable' => ['MariaDB client (custom build)', null];
        yield 'garbage' => ['hello', null];
    }

    #[DataProvider('clientVersions')]
    public function testClientSafetyOption(string $version, ?string $expected): void
    {
        self::assertSame($expected, DumpImporter::safetyOptionFor($version));
    }

    /**
     * EN-M1 PoC: a dump with `\!` and `\T` lines. The stand-in client behaves like mysql: it runs them unless one of
     * the safety options is on argv.
     */
    public function testImportedClientCommandsNeverRun(): void
    {
        $marker = $this->root . '/pwned';
        $tee    = $this->root . '/tee-file';
        $body   = 'safe=0; for a in "$@"; do case "$a" in --system-command=OFF|--sandbox) safe=1;; esac; done' . "\n"
            . 'while IFS= read -r line; do case "$line" in "\\! "*) [ $safe = 0 ] && sh -c "${line#\\\\! }";; "\\T "*) [ $safe = 0 ] && : > "${line#\\\\T }";; esac; done; exit 0';
        file_put_contents($this->root . '/tampered.sql', "CREATE TABLE t (id INT);\n\\! touch " . $marker . "\n\\T " . $tee . "\n");

        foreach (['mysql  Ver 8.4.13 for Linux', 'mariadb from 11.8.9-MariaDB, client 15.2'] as $i => $version) {
            $client = EngineFixtures::client($this->root . '/bin', 'mysql-' . $i, $body, $version);
            (new DumpImporter('mysql://app:pw@db/beacon', $client))->import($this->root . '/tampered.sql');
        }
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($tee);

        // Control: the same stand-in without a safety option does run them (the PoC is meaningful).
        (new Process([EngineFixtures::client($this->root . '/bin', 'raw', $body)], null, null, (string) file_get_contents($this->root . '/tampered.sql')))->run();
        self::assertFileExists($marker);
        (new Filesystem())->remove([$marker, $tee]);

        // A client that supports neither option is refused before it reads the file.
        $old = EngineFixtures::client($this->root . '/bin', 'mysql-old', $body, 'mysql  Ver 8.0.36 for Linux');
        try {
            (new DumpImporter('mysql://app:pw@db/beacon', $old))->import($this->root . '/tampered.sql');
            self::fail('Expected DumpFailedException');
        } catch (DumpFailedException $e) {
            self::assertStringContainsString('cannot disable client commands', $e->getMessage());
        }
        self::assertFileDoesNotExist($marker);

        foreach ([EngineFixtures::script($this->root . '/bin', 'broken-client', 'exit 1'), EngineFixtures::script($this->root . '/bin', 'slow-version', 'sleep 30')] as $bad) {
            try {
                (new DumpImporter('mysql://app:pw@db/beacon', $bad, false, 1))->safetyOption();
                self::fail('Expected DumpFailedException');
            } catch (DumpFailedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * EN-I9: imports take the operation lock.
     */
    public function testImportsTakeTheOperationLock(): void
    {
        $client = EngineFixtures::client($this->root . '/bin', 'mysql-ok', 'cat >/dev/null');
        file_put_contents($this->root . '/in.sql', 'SELECT 1;');
        $lock     = new BackupOperationLock($this->root . '/storage');
        $importer = new DumpImporter('mysql://app:pw@db/beacon', $client, false, 60, $lock);
        $importer->import($this->root . '/in.sql');

        $held = $lock->acquire();
        try {
            try {
                $importer->import($this->root . '/in.sql');
                self::fail('Expected BackupAlreadyRunningException');
            } catch (BackupAlreadyRunningException) {
            }
            self::assertSame(Command::FAILURE, (new CommandTester(new DatabaseImportCommand($importer)))->execute(['file' => $this->root . '/in.sql', '--force' => true]));
        } finally {
            $held->release();
        }

        // A long import refreshes the lock.
        $slow = EngineFixtures::client($this->root . '/bin', 'mysql-slow', 'cat >/dev/null; sleep 6');
        (new DumpImporter('mysql://app:pw@db/beacon', $slow, false, 60, $lock))->import($this->root . '/in.sql');
        $lock->acquire()->release();
    }

    /**
     * EN-L1: `..` in config include paths and symlinked include roots.
     */
    public function testDotDotAndSymlinkedIncludeRootsCannotReachTheFloor(): void
    {
        self::assertSame('config/secrets/prod/x', SecretPathPolicy::normalize('public/../config/secrets/prod/x'));
        self::assertTrue((new SecretPathPolicy())->isForbidden('public/../config/secrets/prod/x'));
        self::assertTrue((new SecretPathPolicy())->isForbidden('../outside/file'));
        self::assertSame('../x', SecretPathPolicy::normalize('a/../../x'));

        try {
            $this->archiver(['public', 'public/../config/secrets'])->create();
            self::fail('Expected InvalidBackupOptionsException');
        } catch (InvalidBackupOptionsException) {
        }

        try {
            (new Processor())->processConfiguration(new Configuration(), [['backup' => ['include_paths' => ['public/../config/secrets']]]]);
            self::fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException) {
        }
        try {
            (new Processor())->processConfiguration(new Configuration(), [['backup' => ['include_paths' => ['/etc']]]]);
            self::fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException) {
        }

        symlink($this->project . '/config/secrets/prod', $this->project . '/vault-root');
        $artifact = $this->archiver(['public', 'vault-root'])->create();
        self::assertSame(['public/index.php'], array_keys($artifact->getChecksums()));
        self::assertStringContainsString('secret path(s) skipped', $artifact->getWarnings()[0]);
    }

    /**
     * EN-L2: escaping symlinks and hard links to floor files.
     */
    public function testEscapingSymlinksAndHardLinksAreSkipped(): void
    {
        symlink($this->root . '/outside/db-credentials.txt', $this->project . '/public/creds.txt');
        if (!@link($this->project . '/.env.local', $this->project . '/public/hard.txt')) {
            self::markTestSkipped('hard links are not supported here');
        }
        file_put_contents($this->project . '/public/normal.txt', 'n');
        link($this->project . '/public/normal.txt', $this->project . '/public/normal-twin.txt');

        $artifact = $this->archiver(['public'])->create();
        $keys     = array_keys($artifact->getChecksums());
        sort($keys);

        self::assertSame(['public/index.php', 'public/normal-twin.txt', 'public/normal.txt'], $keys);
        self::assertContains('1 link(s) pointing outside the project skipped.', $artifact->getWarnings());
        self::assertContains('1 secret path(s) skipped (secrets floor / backup.forbidden_paths).', $artifact->getWarnings());
    }

    /**
     * EN-L3: tar link entries are refused; only MANIFEST-listed files are applied.
     */
    public function testRestoreRefusesLinkEntriesAndUnlistedFiles(): void
    {
        $storage = $this->root . '/storage';
        (new Filesystem())->mkdir($storage);

        // A planted 1.27-style archive with a symlink entry to .env.local.
        $evil = $this->root . '/evil';
        (new Filesystem())->mkdir($evil . '/public');
        symlink($this->project . '/.env.local', $evil . '/public/leak.txt');
        file_put_contents($evil . '/MANIFEST.json', '{"checksums":{}}');
        $leak = $this->plantedArtifact($evil, $storage, 'evil');

        $archiver = $this->archiver(['public'], $storage);
        $verify   = $archiver->verifyIntegrity($leak);
        self::assertFalse($verify['ok']);
        self::assertStringContainsString('Links and special files', implode(' ', $verify['errors']));
        try {
            $this->orchestrator($archiver)->restore($leak);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        self::assertFileDoesNotExist($this->project . '/public/leak.txt');

        // Files absent from the MANIFEST are not applied.
        $mixed = $this->root . '/mixed';
        (new Filesystem())->mkdir($mixed . '/public');
        file_put_contents($mixed . '/public/listed.txt', 'listed');
        file_put_contents($mixed . '/public/unlisted.php', '<?php // planted');
        file_put_contents($mixed . '/MANIFEST.json', (string) json_encode(['checksums' => ['public/listed.txt' => hash_file('sha256', $mixed . '/public/listed.txt')]]));
        $artifact = $this->plantedArtifact($mixed, $storage, 'mixed');
        $ticks    = 0;
        $this->orchestrator($archiver)->restore($artifact, null, static function () use (&$ticks): void {
            ++$ticks;
        });

        self::assertFileExists($this->project . '/public/listed.txt');
        self::assertFileDoesNotExist($this->project . '/public/unlisted.php');
        self::assertGreaterThan(0, $ticks, 'EN-L4: the restore heartbeat reaches the lock refresh callback');
        self::assertSame([], glob($this->root . '/work/nowo-site-backup-restore-*') ?: [], 'EN-I6: staging in work_dir, removed afterwards');
    }

    /**
     * EN-I8: size, ratio and entry-count limits.
     */
    public function testExtractionLimits(): void
    {
        $storage = $this->root . '/storage';
        (new Filesystem())->mkdir($storage);
        file_put_contents($storage . '/bomb.sql.gz', (string) gzencode(str_repeat('A', 5_000_000)));
        $bomb  = new BackupArtifact('bomb', 'bomb.sql.gz', $storage . '/bomb.sql.gz', new DateTimeImmutable(), 1, (string) hash_file('sha256', $storage . '/bomb.sql.gz'), [], ['format' => 'sql.gz', 'plaintext_sha256' => 'x']);
        $small = new BackupArchiver($this->project, $storage, ['public'], [], null, 60, extractLimits: ['max_bytes' => 1_000_000, 'max_entries' => 2]);

        self::assertStringContainsString('backup.extract_limits', implode(' ', $small->verifyIntegrity($bomb)['errors']));
        self::assertSame(1_000_000, $small->getExtractLimits()['max_bytes']);

        $many = $this->root . '/many';
        (new Filesystem())->mkdir($many);
        for ($i = 0; $i < 3; ++$i) {
            file_put_contents($many . '/f' . $i, 'x');
        }
        file_put_contents($many . '/MANIFEST.json', '{"checksums":{}}');
        $tar = $this->plantedArtifact($many, $storage, 'many');
        self::assertStringContainsString('more entries', implode(' ', $small->verifyIntegrity($tar)['errors']));

        // Big entry sizes in the tar listing.
        $big = $this->root . '/big';
        (new Filesystem())->mkdir($big);
        file_put_contents($big . '/MANIFEST.json', '{"checksums":{}}');
        file_put_contents($big . '/big.bin', str_repeat("\0", 2_000_000));
        $bigTar = $this->plantedArtifact($big, $storage, 'big');
        self::assertStringContainsString('backup.extract_limits', implode(' ', (new BackupArchiver($this->project, $storage, ['public'], [], null, 60, extractLimits: ['max_bytes' => 1_000_000]))->verifyIntegrity($bigTar)['errors']));

        if (class_exists(ZipArchive::class)) {
            $zipPath = $storage . '/many.zip';
            $zip     = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
            for ($i = 0; $i < 3; ++$i) {
                $zip->addFromString('f' . $i, 'x');
            }
            $zip->close();
            $zipArtifact = new BackupArtifact('manyzip', 'many.zip', $zipPath, new DateTimeImmutable(), 1, (string) hash_file('sha256', $zipPath));
            self::assertStringContainsString('more entries', implode(' ', $small->verifyIntegrity($zipArtifact)['errors']));

            $bigZipPath = $storage . '/big.zip';
            $zip        = new ZipArchive();
            $zip->open($bigZipPath, ZipArchive::CREATE);
            $zip->addFromString('big.bin', str_repeat("\0", 2_000_000));
            $zip->close();
            $bigZip = new BackupArtifact('bigzip', 'big.zip', $bigZipPath, new DateTimeImmutable(), 1, (string) hash_file('sha256', $bigZipPath));
            self::assertStringContainsString('backup.extract_limits', implode(' ', (new BackupArchiver($this->project, $storage, ['public'], [], null, 60, extractLimits: ['max_bytes' => 1_000_000]))->verifyIntegrity($bigZip)['errors']));
        }
    }

    /**
     * EN-I3 / EN-I7: work_dir hygiene, storage_dir mode, dead-owner cleanup, confined deletes, safe ids.
     */
    public function testWorkDirStorageDirAndDeleteConfinement(): void
    {
        $realWork = $this->root . '/real-work';
        mkdir($realWork, 0o700);
        symlink($realWork, $this->root . '/work-link');
        try {
            (new BackupArchiver($this->project, $this->root . '/s1', ['public'], [], null, 60, workDir: $this->root . '/work-link'))->create();
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }

        // storage_dir is created owner-only whatever the umask.
        // @phpstan-ignore-next-line (test only, restored below)
        $old = umask(0);
        try {
            $this->archiver(['public'], $this->root . '/fresh-storage')->create();
        } finally {
            // @phpstan-ignore-next-line
            umask($old);
        }
        self::assertSame(0o700, fileperms($this->root . '/fresh-storage') & 0o777);

        // A run directory whose owner process is gone is removed at once.
        $work = $this->root . '/work';
        mkdir($work . '/nowo-site-backup-dead-1', 0o700, true);
        file_put_contents($work . '/nowo-site-backup-dead-1/.owner', gethostname() . ' 999999999');
        mkdir($work . '/nowo-site-backup-foreign-1', 0o700);
        file_put_contents($work . '/nowo-site-backup-foreign-1/.owner', 'another-host 999999999');
        $archiver = $this->archiver(['public']);
        $archiver->create();
        self::assertDirectoryDoesNotExist($work . '/nowo-site-backup-dead-1');
        self::assertDirectoryExists($work . '/nowo-site-backup-foreign-1');

        // A sidecar pointing outside storage_dir never deletes that file; unsafe ids are not found.
        $storage = $this->root . '/storage';
        file_put_contents($this->root . '/victim.txt', 'keep me');
        $planted = new BackupArtifact('planted-1', 'planted-1.tar.gz', $this->root . '/victim.txt', new DateTimeImmutable(), 1, 'x');
        file_put_contents($storage . '/planted-1.meta.json', (string) json_encode($planted->toArray()));
        self::assertFalse($archiver->delete('planted-1'));
        self::assertFileExists($this->root . '/victim.txt');
        self::assertNull($archiver->find('../planted-1'));
        self::assertNull($archiver->find('a..b'));
    }

    public function testExtendedFloorAndRedactor(): void
    {
        $policy = new SecretPathPolicy();
        foreach (['.ssh/id_ecdsa', 'id_dsa.pub', 'public/.htpasswd', 'gcp/credentials.json', 'home/.aws/credentials', '.aws/credentials', '.docker/config.json', 'keys/putty.ppk', 'vault.kdbx'] as $path) {
            self::assertTrue($policy->isForbidden($path), $path);
        }
        self::assertFalse($policy->isForbidden('config/docker/config.json'));

        $message = SecretRedactor::redact('{"password": "CANARY-1", "api_key":"CANARY-2"} CREATE USER x IDENTIFIED BY \'CANARY-3\'; APP_SECRET=CANARY-4 MAILER_DSN_KEY="CANARY-5" token: CANARY-6 Authorization: Bearer CANARY-7 Basic Q0FOQVJZLTg6eA==');
        self::assertStringNotContainsString('CANARY', $message);
        self::assertStringContainsString('Basic error', SecretRedactor::redact('Basic error'));
    }

    public function testExtraOptionsSpellingsAreNormalised(): void
    {
        foreach (['--loose-result-file=/tmp/x', '--result_file=/tmp/x', '--loose-defaults-extra-file=/x', '--loose-password=x', '--all-databases', '--databases', '--maximum-init_command=x'] as $bad) {
            try {
                BuiltinMysqlDumper::assertExtraOptions([$bad]);
                self::fail('Expected refusal of ' . $bad);
            } catch (InvalidBackupOptionsException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<string> $includes
     */
    private function archiver(array $includes, ?string $storage = null): BackupArchiver
    {
        return new BackupArchiver($this->project, $storage ?? $this->root . '/storage', $includes, [], null, 60, workDir: $this->root . '/work');
    }

    private function orchestrator(BackupArchiver $archiver): RestoreOrchestrator
    {
        return new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), [], triggerSetupAfterRestore: false);
    }

    private function plantedArtifact(string $payload, string $storage, string $id): BackupArtifact
    {
        $archive = $storage . '/' . $id . '.tar.gz';
        (new Process(['tar', '-czf', $archive, '-C', $payload, '.']))->mustRun();

        return new BackupArtifact($id, $id . '.tar.gz', $archive, new DateTimeImmutable(), 1, (string) hash_file('sha256', $archive), [], ['format' => ArchiveFormat::TarGz->value]);
    }
}
