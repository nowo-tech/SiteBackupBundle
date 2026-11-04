<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\SchemaSnapshot;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\InsufficientDiskException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\SecretsInArchiveException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function gzdecode;
use function is_dir;
use function json_decode;
use function mkdir;
use function scandir;
use function str_repeat;
use function symlink;
use function touch;

use const PHP_INT_MAX;

final class ArchiverFormatsTest extends TestCase
{
    private string $root;
    private string $project;
    private string $storage;
    private string $work;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-formats-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        $this->work    = $this->root . '/work';
        $fs            = new Filesystem();
        $fs->mkdir([$this->project . '/config/secrets/prod', $this->project . '/config/jwt', $this->project . '/public', $this->project . '/var/secrets']);
        file_put_contents($this->project . '/config/app.yaml', str_repeat("key: value\n", 200));
        file_put_contents($this->project . '/config/secrets/prod/prod.decrypt.private.php', 'CANARY-vault-key');
        file_put_contents($this->project . '/config/jwt/private.pem', 'CANARY-jwt');
        file_put_contents($this->project . '/var/secrets/halite.key', 'CANARY-halite');
        file_put_contents($this->project . '/public/index.php', "<?php\n");
        file_put_contents($this->project . '/public/server.key', 'CANARY-tls');
        file_put_contents($this->project . '/.env', 'APP_SECRET=CANARY-env');
        file_put_contents($this->project . '/.env.local', 'APP_SECRET=CANARY-env-local');
        file_put_contents($this->project . '/.env.dist', 'APP_SECRET=change-me');
        file_put_contents($this->project . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testTarGzSkipsEverySecretAndRestoresNothingSecret(): void
    {
        $archiver = $this->archiver(includePaths: ['config', 'public', '.env', '.env.local', '.env.dist', 'composer.json', 'var/secrets']);
        $beats    = [];
        $artifact = $archiver->create(new BackupOptions(compressionLevel: 9, label: 'l', heartbeat: static function (BackupProgress $p) use (&$beats): void {
            $beats[] = $p->phase;
        }));

        self::assertSame(ArchiveFormat::TarGz, $artifact->getFormat());
        self::assertStringEndsWith('.tar.gz', $artifact->getFilename());
        self::assertSame(['.env.dist', 'composer.json', 'config/app.yaml', 'public/index.php'], $this->sortedKeys($artifact->getChecksums()));
        self::assertSame(['6 secret path(s) skipped (secrets floor / backup.forbidden_paths).'], $artifact->getWarnings());
        self::assertSame('files', $artifact->getContent());
        self::assertSame(0o600, fileperms($artifact->getAbsolutePath()) & 0o777);
        self::assertSame(0o600, fileperms($this->storage . '/' . $artifact->getId() . '.meta.json') & 0o777);
        self::assertContains(BackupProgress::PHASE_COPYING, $beats);
        self::assertContains(BackupProgress::PHASE_DONE, $beats);
        self::assertTrue($archiver->verifyIntegrity($artifact)['ok']);
        self::assertStringNotContainsString('CANARY', (string) file_get_contents($this->storage . '/' . $artifact->getId() . '.meta.json'));
        $this->assertWorkDirEmpty();

        $extract = $this->root . '/extract';
        $archiver->extractTo($artifact, $extract);
        $manifest = json_decode((string) file_get_contents($extract . '/MANIFEST.json'), true);
        self::assertIsArray($manifest);
        self::assertSame(2, $manifest['version']);
        self::assertSame('tar.gz', $manifest['meta']['format']);
        self::assertSame(9, $manifest['meta']['compression_level']);
        self::assertFileDoesNotExist($extract . '/.env');
    }

    public function testZipRoundTrip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip');
        }
        $archiver = $this->archiver(includePaths: ['config', 'public']);
        $artifact = $archiver->create(new BackupOptions(format: ArchiveFormat::Zip, heartbeat: static function (): void {
        }));
        $stored = $archiver->create(new BackupOptions(format: ArchiveFormat::Zip, compressionLevel: 0));

        self::assertStringEndsWith('.zip', $artifact->getFilename());
        self::assertSame(ArchiveFormat::Zip, $archiver->find($artifact->getId())?->getFormat());
        self::assertTrue($archiver->verifyIntegrity($artifact)['ok']);
        self::assertTrue($archiver->verifyIntegrity($stored)['ok']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($artifact->getAbsolutePath()));
        self::assertNotFalse($zip->locateName('MANIFEST.json'));
        self::assertFalse($zip->locateName('config/secrets/prod/prod.decrypt.private.php'));
        $zip->close();
    }

    public function testZipGuardRefusesUnsafeArchives(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip');
        }
        $archiver = $this->archiver(includePaths: ['config']);

        $cases = [
            'slip'      => static fn (ZipArchive $z) => $z->addFromString('../evil.php', 'x'),
            'encrypted' => static function (ZipArchive $z): void {
                $z->addFromString('MANIFEST.json', '{}');
                $z->setEncryptionName('MANIFEST.json', ZipArchive::EM_AES_256, 'canary-zip-0000000');
            },
            'symlink' => static function (ZipArchive $z): void {
                $z->addFromString('link', '/etc/passwd');
                $z->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0o120777 << 16);
            },
        ];
        if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            unset($cases['encrypted']);
        }
        foreach ($cases as $name => $build) {
            $path = $this->storage . '/' . $name . '.zip';
            (new Filesystem())->mkdir($this->storage);
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $build($zip);
            $zip->close();
            $artifact = new BackupArtifact($name, $name . '.zip', $path, new DateTimeImmutable(), 1, (string) hash_file('sha256', $path));

            $result = $archiver->verifyIntegrity($artifact);
            self::assertFalse($result['ok'], $name);
            self::assertNotSame([], $result['errors']);
        }

        $notZip = $this->storage . '/garbage.zip';
        file_put_contents($notZip, 'not a zip');
        self::assertFalse($archiver->verifyIntegrity(new BackupArtifact('g', 'garbage.zip', $notZip, new DateTimeImmutable(), 1, 'x'))['ok']);
    }

    public function testEntryNameGuard(): void
    {
        BackupArchiver::assertSafeEntryName('config/app.yaml');
        foreach (['', '/etc/passwd', 'a/../../b', '..', 'a\\b', 'C:/x', "a\0b"] as $bad) {
            try {
                BackupArchiver::assertSafeEntryName($bad);
                self::fail('Expected refusal');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSqlGzWithTheBuiltinDumper(): void
    {
        $bin       = EngineFixtures::tempDir('sbb-bin-');
        $inspector = new FixedSchemaInspector(SchemaSnapshot::fromRows(['user', 'event_log'], [], [], []));
        $dumper    = new BuiltinMysqlDumper('mysql://app:' . EngineFixtures::DB_CANARY . '@db/beacon', EngineFixtures::fakeMysqldump($bin), schemaInspector: $inspector);
        $archiver  = $this->archiver(includePaths: ['config'], dumper: $dumper);

        try {
            $artifact = $archiver->create(new BackupOptions(includeFiles: false, format: ArchiveFormat::SqlGz, tableRules: ['event_log' => TableRule::StructureOnly]));

            self::assertSame('database', $artifact->getContent());
            self::assertSame(['event_log' => 'structure'], $artifact->getTableRules());
            self::assertNotNull($artifact->getPlaintextSha256());
            self::assertSame('mysql', $artifact->getMeta()['dump']['client'] ?? null);
            $sql = (string) gzdecode((string) file_get_contents($artifact->getAbsolutePath()));
            self::assertStringContainsString('--ignore-table=beacon.event_log', $sql);
            self::assertTrue($archiver->verifyIntegrity($artifact)['ok']);

            $extract = $this->root . '/sql-extract';
            $archiver->extractTo($artifact, $extract);
            self::assertSame($sql, file_get_contents($extract . '/database/dump.sql'));

            // Database + files in a tar.gz with the builtin dumper.
            $both = $archiver->create(new BackupOptions(format: ArchiveFormat::TarGz));
            self::assertSame('database_files', $both->getContent());
            self::assertArrayHasKey('database/dump.sql', $both->getChecksums());

            // A tampered sql.gz and a sidecar without plaintext checksum fail verification.
            file_put_contents($artifact->getAbsolutePath(), (string) gzencode('tampered'));
            $tampered = new BackupArtifact($artifact->getId(), $artifact->getFilename(), $artifact->getAbsolutePath(), $artifact->getCreatedAt(), 1, (string) hash_file('sha256', $artifact->getAbsolutePath()), [], $artifact->getMeta());
            self::assertSame(['Checksum mismatch for database/dump.sql'], $archiver->verifyIntegrity($tampered)['errors']);
            $noSha = new BackupArtifact($artifact->getId(), $artifact->getFilename(), $artifact->getAbsolutePath(), $artifact->getCreatedAt(), 1, (string) hash_file('sha256', $artifact->getAbsolutePath()));
            self::assertStringContainsString('plaintext_sha256', $archiver->verifyIntegrity($noSha)['errors'][0]);
            file_put_contents($artifact->getAbsolutePath(), 'not gzip');
            self::assertFalse($archiver->verifyIntegrity($tampered)['ok']);
        } finally {
            (new Filesystem())->remove($bin);
        }
    }

    public function testUnsupportedCombinationsAreRefusedBeforeAnyWork(): void
    {
        $archiver = $this->archiver(includePaths: ['config']);

        foreach ([
            new BackupOptions(tableRules: ['t' => TableRule::Skip]),
            new BackupOptions(includeFiles: false),
        ] as $options) {
            try {
                $archiver->create($options);
                self::fail('Expected InvalidBackupOptionsException');
            } catch (InvalidBackupOptionsException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertFalse(is_dir($this->storage) && scandir($this->storage) !== ['.', '..']);
    }

    public function testFinalGuardRefusesAForbiddenPathThatSlippedThrough(): void
    {
        // A forbidden_paths pattern that only the final guard sees: the copier is given a policy that allows it.
        $archiver = new BackupArchiver(
            projectDir: $this->project,
            storageDir: $this->storage,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'echo "SELECT 1;"',
            processTimeoutSeconds: 60,
            secretPolicy: new SecretPathPolicy(['database/*']),
            workDir: $this->work,
        );

        try {
            $archiver->create(new BackupOptions());
            self::fail('Expected SecretsInArchiveException');
        } catch (SecretsInArchiveException $e) {
            self::assertSame(['database/dump.sql'], $e->getPaths());
        }
        self::assertSame([], glob($this->storage . '/*') ?: []);
        $this->assertWorkDirEmpty();
    }

    public function testSymlinksIntoSecretsAreSkipped(): void
    {
        symlink($this->project . '/var/secrets/halite.key', $this->project . '/config/innocent.txt');
        symlink('/nonexistent/target', $this->project . '/config/dangling.txt');
        $outside = $this->root . '/outside.pem';
        file_put_contents($outside, 'CANARY-outside');
        symlink($outside, $this->project . '/config/cert.txt');
        file_put_contents($this->root . '/shared.txt', 'shared');
        symlink($this->root . '/shared.txt', $this->project . '/config/shared.txt');

        $artifact = $this->archiver(includePaths: ['config'])->create();

        // 1.28 EN-L2: links leaving the project are skipped too, whatever their name.
        self::assertSame(['config/app.yaml'], $this->sortedKeys($artifact->getChecksums()));
        self::assertContains('2 link(s) pointing outside the project skipped.', $artifact->getWarnings());
    }

    public function testExtraForbiddenPathsAndDefaults(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->project,
            storageDir: $this->storage,
            includePaths: ['config', 'public'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
            secretPolicy: new SecretPathPolicy(['public/*']),
        );

        $artifact = $archiver->create('legacy-label', 'legacy-actor');

        self::assertSame(['config/app.yaml'], $this->sortedKeys($artifact->getChecksums()));
        self::assertSame('legacy-label', $artifact->getLabel());
        self::assertSame('legacy-actor', $artifact->getCreatedBy());
        self::assertFalse($artifact->isEncrypted());
        self::assertFalse($archiver->hasDatabaseDumper());
        self::assertTrue($archiver->getSecretPathPolicy()->isForbidden('.env'));
    }

    public function testStaleWorkDirectoriesArePrunedAndWorkDirIsOwnerOnly(): void
    {
        $clock    = new MockClock(new DateTimeImmutable('2026-11-04 12:00:00'));
        $archiver = $this->archiver(includePaths: ['config'], clock: $clock);
        mkdir($this->work, 0o777);
        mkdir($this->work . '/nowo-site-backup-old-1');
        touch($this->work . '/nowo-site-backup-old-1', $clock->now()->getTimestamp() - 7200);
        mkdir($this->work . '/nowo-site-backup-fresh-1');
        touch($this->work . '/nowo-site-backup-fresh-1', $clock->now()->getTimestamp());
        mkdir($this->work . '/other');
        touch($this->work . '/other', 0);

        $archiver->create();

        self::assertSame(0o700, fileperms($this->work) & 0o777);
        self::assertDirectoryDoesNotExist($this->work . '/nowo-site-backup-old-1');
        self::assertDirectoryExists($this->work . '/nowo-site-backup-fresh-1');
        self::assertDirectoryExists($this->work . '/other');
        self::assertSame(1, $archiver->removeStaleWorkDirectories(0));
        self::assertSame(0, (new BackupArchiver($this->project, $this->storage, [], [], null, 60))->removeStaleWorkDirectories(0));
    }

    public function testPreflightRefusesWhenTheLastArchiveCannotFitThreeTimes(): void
    {
        $archiver = $this->archiver(includePaths: ['config'], preflight: true);
        $archiver->create(); // no previous archive: no estimate, passes

        (new Filesystem())->mkdir($this->storage);
        $huge = new BackupArtifact('20000101-000000-ffffffff', 'huge.tar.gz', $this->storage . '/huge.tar.gz', new DateTimeImmutable('2100-01-01'), PHP_INT_MAX >> 4, 'x');
        file_put_contents($this->storage . '/zzzz.meta.json', (string) json_encode($huge->toArray()));

        $this->expectException(InsufficientDiskException::class);
        $archiver->create();
    }

    public function testFailedDumpLeavesNothingBehind(): void
    {
        $archiver = new BackupArchiver(
            projectDir: $this->project,
            storageDir: $this->storage,
            includePaths: ['config'],
            excludePatterns: [],
            databaseDumpCommand: 'echo "password=CANARY-db" >&2; exit 3',
            processTimeoutSeconds: 60,
            workDir: $this->work,
        );

        try {
            $archiver->create();
            self::fail('Expected a failure');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('Database dump failed: ', $e->getMessage());
            self::assertStringNotContainsString('CANARY-db', $e->getMessage());
        }
        self::assertSame([], glob($this->storage . '/*') ?: []);
        $this->assertWorkDirEmpty();
    }

    public function testTarFailureAndGzipHelpersFailCleanly(): void
    {
        $archiver = $this->archiver(includePaths: ['config']);
        (new Filesystem())->mkdir($this->storage);
        file_put_contents($this->storage . '/exists.tar.gz', 'x');

        $createTarGz = new ReflectionMethod(BackupArchiver::class, 'createTarGz');
        try {
            $createTarGz->invoke($archiver, $this->project, $this->storage . '/exists.tar.gz');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('unable to create', $e->getMessage());
        }

        $gzip = new ReflectionMethod(BackupArchiver::class, 'gzipFile');
        try {
            $gzip->invoke($archiver, $this->root . '/missing.sql', $this->root . '/out.gz', 6, null);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('gzip failed', $e->getMessage());
        }

        $gunzip = new ReflectionMethod(BackupArchiver::class, 'gunzipFile');
        try {
            $gunzip->invoke($archiver, $this->root . '/missing.gz', $this->root . '/out/x.sql', null);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('gunzip failed', $e->getMessage());
        }

        $zip = new ReflectionMethod(BackupArchiver::class, 'createZip');
        try {
            $zip->invoke($archiver, $this->project . '/config', $this->storage . '/exists.tar.gz', 6, null);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('zip create failed', $e->getMessage());
        }
    }

    public function testArtifactGettersForLegacyAndNewSidecars(): void
    {
        $legacy = BackupArtifact::fromArray(['id' => 'a', 'filename' => 'a.tar.gz', 'absolute_path' => '/x/a.tar.gz', 'meta' => ['database_dump' => 'database/dump.sql']]);
        self::assertSame(ArchiveFormat::TarGz, $legacy->getFormat());
        self::assertSame('database_files', $legacy->getContent());
        self::assertSame([], $legacy->getTableRules());
        self::assertSame([], $legacy->getWarnings());
        self::assertNull($legacy->getPlaintextSha256());

        $odd = BackupArtifact::fromArray(['id' => 'b', 'filename' => 'b.zip', 'absolute_path' => '/x/b.zip', 'meta' => ['format' => 'rar', 'table_rules' => ['t' => 'skip', 3 => 'x'], 'warnings' => ['w', 3], 'encrypted' => true]]);
        self::assertSame(ArchiveFormat::Zip, $odd->getFormat());
        self::assertSame('files', $odd->getContent());
        self::assertSame(['t' => 'skip'], $odd->getTableRules());
        self::assertSame(['w'], $odd->getWarnings());
        self::assertTrue($odd->isEncrypted());
    }

    /**
     * @param list<string> $includePaths
     */
    private function archiver(array $includePaths, ?BuiltinMysqlDumper $dumper = null, ?MockClock $clock = null, bool $preflight = false): BackupArchiver
    {
        return new BackupArchiver(
            projectDir: $this->project,
            storageDir: $this->storage,
            includePaths: $includePaths,
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
            clock: $clock ?? new MockClock(),
            builtinDumper: $dumper,
            workDir: $this->work,
            preflightDiskCheck: $preflight,
        );
    }

    /**
     * @param array<string, string> $checksums
     *
     * @return list<string>
     */
    private function sortedKeys(array $checksums): array
    {
        $keys = array_keys($checksums);
        sort($keys);

        return $keys;
    }

    private function assertWorkDirEmpty(): void
    {
        self::assertSame([], glob($this->work . '/nowo-site-backup-*') ?: [], 'every run removes its work dir');
    }
}
