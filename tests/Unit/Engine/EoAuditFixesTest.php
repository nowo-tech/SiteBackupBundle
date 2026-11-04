<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use LogicException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Crypto\AgeBinaryEncryptor;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\NowoSiteBackupBundle;
use Nowo\SiteBackupBundle\Remote\InMemoryRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use ZipArchive;

use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function json_decode;
use function json_encode;
use function print_r;
use function rewind;
use function strlen;
use function var_export;

/**
 * Fixes for the 1.29.0 crypto audit (appendix EO). Placeholder values only.
 */
final class EoAuditFixesTest extends TestCase
{
    private const PHRASE = 'canary-eo-phrase-0000-placeholder';

    private string $root;
    private string $project;

    protected function setUp(): void
    {
        $this->root    = EngineFixtures::tempDir('sbb-eo-');
        $this->project = $this->root . '/project';
        (new Filesystem())->mkdir([$this->project . '/public', $this->root . '/bin', $this->root . '/in']);
        file_put_contents($this->project . '/public/index.php', '<?php echo "genuine";');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * EO-M1: plaintext and mixed archives are refused when a password is given; "encrypted" comes from the
     * container.
     */
    public function testDowngradeIsRefused(): void
    {
        $this->requireZipAes();
        $archiver = $this->archiver();
        $phrase   = ArchivePassword::fromString(self::PHRASE);

        // A genuine encrypted zip, then the PoC: the same entries with the webshell planted in plaintext.
        $genuine = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword($phrase));
        $mixed   = $this->root . '/in/mixed.zip';
        copy($genuine->getAbsolutePath(), $mixed);
        $zip = new ZipArchive();
        $zip->open($mixed);
        $zip->deleteName('public/index.php');
        $zip->addFromString('public/index.php', '<?php system($_GET["c"]);');
        $zip->close();
        self::assertSame('mixed', BackupArchiver::zipEncryption($mixed));

        $plainTar = $archiver->create(new BackupOptions());
        $plainZip = $archiver->create(new BackupOptions(format: ArchiveFormat::Zip));

        foreach ([$mixed, $plainTar->getAbsolutePath(), $plainZip->getAbsolutePath()] as $path) {
            self::assertSame(VerifyFileResult::NOT_ENCRYPTED, $archiver->verifyFile($path, $phrase)->status, $path);
            try {
                $archiver->import($path, $phrase);
                self::fail('Expected CorruptArchiveException for ' . $path);
            } catch (CorruptArchiveException $e) {
                self::assertSame(CorruptArchiveException::NOT_ENCRYPTED, $e->getCode());
            }
        }

        // A mixed zip is refused even with allowPlaintext; a plaintext archive is accepted only with it.
        try {
            $archiver->import($mixed, $phrase, null, new ImportOptions(allowPlaintext: true));
            self::fail('Expected CorruptArchiveException');
        } catch (CorruptArchiveException) {
        }
        self::assertSame(VerifyFileResult::NOT_ENCRYPTED, $archiver->verifyFile($mixed)->status);
        self::assertTrue($archiver->verifyFile($plainTar->getAbsolutePath(), $phrase, allowPlaintext: true)->isOk());

        // "encrypted" is never taken from the MANIFEST.
        $payload = $this->root . '/liar';
        (new Filesystem())->mkdir($payload);
        file_put_contents($payload . '/MANIFEST.json', (string) json_encode(['checksums' => [], 'meta' => ['encrypted' => true, 'key_id' => 'k']]));
        (new Process(['tar', '-czf', $this->root . '/in/liar.tar.gz', '-C', $payload, '.']))->mustRun();
        $imported = $archiver->import($this->root . '/in/liar.tar.gz');
        self::assertFalse($imported->getMeta()['imported_from']['encrypted']);

        $ok = $archiver->import($genuine->getAbsolutePath(), $phrase);
        self::assertTrue($ok->getMeta()['imported_from']['encrypted']);
    }

    /**
     * EO-M2: signatures are required once a key is configured; stripping one does not help.
     */
    public function testSignaturesAreRequiredWhenAKeyIsConfigured(): void
    {
        $pair     = BackupSigner::generateKeyPair();
        $signer   = new BackupSigner($pair['secret_key'], 'k1');
        $archiver = $this->archiver($signer);
        $artifact = $archiver->create();
        $sidecar  = (array) json_decode((string) file_get_contents($this->root . '/storage/' . $artifact->getId() . '.meta.json'), true);
        self::assertTrue($signer->isRequired());
        self::assertFalse((new BackupSigner($pair['secret_key'], 'k1', [], false))->isRequired());

        $stripped = $sidecar;
        unset($stripped['meta']['signature']);
        self::assertSame(VerifyFileResult::SIGNATURE, $archiver->verifyFile($artifact->getAbsolutePath(), null, null, $stripped)->status);
        self::assertSame(VerifyFileResult::SIGNATURE, $archiver->verifyFile($artifact->getAbsolutePath())->status, 'no sidecar at all');
        self::assertTrue($archiver->verifyFile($artifact->getAbsolutePath(), null, null, $sidecar)->isOk());
        self::assertTrue($archiver->verifyFile($artifact->getAbsolutePath(), null, null, null, false, false)->isOk(), 'explicitly not required');
        self::assertSame(VerifyFileResult::SIGNATURE, $this->archiver(new BackupSigner())->verifyFile($artifact->getAbsolutePath(), null, null, $sidecar, false, true)->status, 'required but no key');

        // Local sidecar with the signature stripped.
        file_put_contents($this->root . '/storage/' . $artifact->getId() . '.meta.json', (string) json_encode($stripped));
        $local = $archiver->find($artifact->getId());
        self::assertNotNull($local);
        self::assertContains('Signature missing: a signed sidecar is required (backup.signing.required).', $archiver->verifyIntegrity($local)['errors']);

        // Import needs the signed sidecar.
        try {
            $archiver->import($artifact->getAbsolutePath());
            self::fail('Expected CorruptArchiveException');
        } catch (CorruptArchiveException $e) {
            self::assertSame(CorruptArchiveException::SIGNATURE, $e->getCode());
        }
        $imported = $archiver->import($artifact->getAbsolutePath(), null, null, new ImportOptions(sidecar: $sidecar));
        self::assertTrue($archiver->verifyIntegrity($imported)['ok'], 'imports are signed too');

        // Prune ignores unsigned sidecars when signatures are required.
        $clock     = new MockClock('2026-11-04 03:00:00');
        $target    = new InMemoryRemoteTarget();
        $unsigned  = $this->archiver(null, $clock);
        $publisher = new RemoteBackupPublisher(null, $clock, $signer);
        $old       = $unsigned->create();
        $publisher->publish($old, $target, 'p/', 'p/' . $old->getId() . '.tar.gz');
        $clock->sleep(86400);
        $new = $unsigned->create();
        $publisher->publish($new, $target, 'p/', 'p/' . $new->getId() . '.tar.gz');
        self::assertSame([], $publisher->prune($target, 'p/', new RetentionPolicy(keepLast: 0), $new->getId())->deletedKeys);
        self::assertSame(['p/' . $old->getId() . '.tar.gz'], $publisher->prune($target, 'p/', new RetentionPolicy(keepLast: 0), $new->getId(), null, false)->deletedKeys);
    }

    /**
     * EO-M3: imports into another schema refuse dumps that switch database, and run with --one-database.
     */
    public function testDrillImportsRefuseDatabaseSwitches(): void
    {
        $refused = [
            "USE app;\nDELETE FROM t;\n",
            "\\u app\n",
            "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `app`;\n",
            "  /*!40000 USE `app` */;\n",
            "-- Current Database: `app`\n",
            "drop schema app;\n",
            "SELECT 1;\nALTER DATABASE app CHARACTER SET utf8mb4;\n",
            "\\r app\n",
            "CONNECT app\n",
        ];
        foreach ($refused as $i => $sql) {
            file_put_contents($this->root . '/in/d' . $i . '.sql', $sql);
            try {
                DumpImporter::assertSingleDatabaseDump($this->root . '/in/d' . $i . '.sql');
                self::fail('Expected refusal of ' . $sql);
            } catch (DumpFailedException) {
                self::addToAssertionCount(1);
            }
        }

        // A genuine single-database dump (with a very long line) passes.
        file_put_contents($this->root . '/in/ok.sql', "-- Host: db    Database: app\n/*!40101 SET NAMES utf8mb4 */;\nINSERT INTO t VALUES ('" . str_repeat('user;USE x', 20000) . "');\nCREATE TABLE users (id INT);\n");
        DumpImporter::assertSingleDatabaseDump($this->root . '/in/ok.sql');

        $client   = EngineFixtures::client($this->root . '/bin', 'mysql-args', 'echo "$*" > "' . $this->root . '/argv"; cat >/dev/null');
        $importer = new DumpImporter('mysql://app:pw@db/app', $client);
        $importer->importInto($this->root . '/in/ok.sql', 'drill_1');
        self::assertStringContainsString('--one-database -- drill_1', (string) file_get_contents($this->root . '/argv'));

        (new Filesystem())->remove($this->root . '/argv');
        try {
            $importer->importInto($this->root . '/in/d0.sql', 'drill_1');
            self::fail('Expected DumpFailedException');
        } catch (DumpFailedException) {
        }
        self::assertFileDoesNotExist($this->root . '/argv', 'the client never started');

        $this->expectException(DumpFailedException::class);
        DumpImporter::assertSingleDatabaseDump($this->root . '/in/missing.sql');
    }

    /**
     * EO-L1: no dumper prints the password.
     */
    public function testPasswordIsInvisibleToEveryDumper(): void
    {
        $password = ArchivePassword::fromString(self::PHRASE);
        $options  = (new BackupOptions())->withPassword($password, 'k1');
        NowoSiteBackupBundle::registerVarDumperCasters();
        $cli    = new CliDumper();
        $dumped = '';
        foreach ([$password, $options] as $value) {
            $dumped .= var_export($value, true) . print_r((array) $value, true) . print_r($value, true);
            $dumped .= (string) $cli->dump((new VarCloner())->cloneVar($value), true);
        }
        self::assertStringNotContainsString(self::PHRASE, $dumped);
        self::assertStringContainsString('***', (string) $cli->dump((new VarCloner())->cloneVar($password), true));
        self::assertSame(self::PHRASE, $password->reveal());

        $clone = clone $password;
        $this->expectException(LogicException::class);
        $clone->reveal();
    }

    /**
     * EO-L3 / EO-L4: planted sidecars do not drive prune; publish never overwrites an existing key.
     */
    public function testPlantedSidecarsAndExistingKeys(): void
    {
        $clock     = new MockClock('2026-11-04 03:00:00');
        $archiver  = $this->archiver(null, $clock);
        $target    = new InMemoryRemoteTarget();
        $publisher = new RemoteBackupPublisher(null, $clock);
        $genuine   = [];
        for ($i = 0; $i < 3; ++$i) {
            $artifact = $archiver->create();
            $publisher->publish($artifact, $target, 'p/', 'p/' . $artifact->getId() . '.tar.gz');
            $genuine[] = $artifact->getId();
            $clock->sleep(3600);
        }

        // A bucket writer plants future-dated, mismatching sidecars.
        foreach (['20300101-000000-aaaaaaaa', '20300101-000000-bbbbbbbb'] as $id) {
            $this->put($target, 'p/' . $id . '.tar.gz', 'x');
            $this->put($target, 'p/' . $id . '.tar.gz.backup.json', (string) json_encode(['id' => $id, 'created_at' => '2030-01-01T00:00:00+00:00', 'size' => 1]));
        }
        $this->put($target, 'p/other.tar.gz', 'x');
        $this->put($target, 'p/other.tar.gz.backup.json', (string) json_encode(['id' => $genuine[0], 'created_at' => '2026-11-04T09:00:00+00:00', 'size' => 1]));
        $this->put($target, 'p/20261104-000000-cccccccc.tar.gz', 'xx');
        $this->put($target, 'p/20261104-000000-cccccccc.tar.gz.backup.json', (string) json_encode(['id' => '20261104-000000-cccccccc', 'created_at' => '2026-11-04T05:00:00+00:00', 'size' => 1]));

        $report = $publisher->prune($target, 'p/', new RetentionPolicy(keepLast: 1), $genuine[2]);
        self::assertSame(['p/' . $genuine[0] . '.tar.gz', 'p/' . $genuine[1] . '.tar.gz'], $report->deletedKeys, 'only genuine, older backups');
        self::assertNotNull($target->head('p/' . $genuine[2] . '.tar.gz'));

        // Publishing to an existing key fails and leaves the existing object as it was.
        try {
            $publisher->publish($archiver->create(), $target, 'p/', 'p/' . $genuine[2] . '.tar.gz');
            self::fail('Expected RemoteTargetException');
        } catch (RemoteTargetException) {
        }
        self::assertNotNull($target->head('p/' . $genuine[2] . '.tar.gz'));
        self::assertNotNull($target->head('p/' . $genuine[2] . '.tar.gz.backup.json'));
    }

    /**
     * EO-L5: the plugin must sit next to the pinned binary; age runs with PATH = that directory only.
     */
    public function testAgeBinaryAndPluginArePinned(): void
    {
        $pinned = $this->root . '/pinned';
        $impost = $this->root . '/impostor';
        (new Filesystem())->mkdir([$pinned, $impost]);
        $age = EngineFixtures::fakeAge($pinned, 'v1.3.1', false);
        EngineFixtures::script($impost, 'age-plugin-batchpass', 'exit 0');
        $path = getenv('PATH');
        // @phpstan-ignore-next-line (test process only, restored below)
        putenv('PATH=' . $impost . ':' . $path);
        try {
            try {
                (new AgeBinaryEncryptor($age))->assertAvailable();
                self::fail('Expected FormatUnavailableException');
            } catch (FormatUnavailableException $e) {
                self::assertStringContainsString('next to the age binary', $e->getMessage());
            }
        } finally {
            // @phpstan-ignore-next-line
            putenv('PATH=' . $path);
        }

        EngineFixtures::script($pinned, 'age-plugin-batchpass', 'exit 0');
        try {
            (new AgeBinaryEncryptor($age, plugin: $impost . '/age-plugin-batchpass'))->assertAvailable();
            self::fail('Expected FormatUnavailableException');
        } catch (FormatUnavailableException) {
        }
        (new AgeBinaryEncryptor($age, plugin: $pinned . '/age-plugin-batchpass'))->assertAvailable();
        $this->expectException(FormatUnavailableException::class);
        new AgeBinaryEncryptor($age, 18, 23);
    }

    /**
     * EO-L6 / EO-I1: imported archives are deleted after the restore; tampered encrypted data is an integrity failure.
     */
    public function testDeleteAfterRestoreAndTamperClassification(): void
    {
        $this->requireZipAes();
        $archiver = $this->archiver();
        $manager  = new SiteBackupManager($archiver, new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), [], triggerSetupAfterRestore: false), new MemoryHistoryStorage());
        $source   = $archiver->create();

        $imported = $manager->importArchive($source->getAbsolutePath());
        $manager->restore($imported->getId());
        self::assertNull($manager->getBackup($imported->getId()), 'deleted after the restore');
        $kept = $manager->importArchive($source->getAbsolutePath(), null, null, new ImportOptions(deleteAfterRestore: false));
        $manager->restore($kept->getId());
        self::assertNotNull($manager->getBackup($kept->getId()));

        // A flipped byte inside an AES entry: integrity failure (1), not "wrong password" (2).
        $zip        = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword(ArchivePassword::fromString(self::PHRASE)));
        $bytes      = (string) file_get_contents($zip->getAbsolutePath());
        $at         = self::middleOfFirstEntryData($bytes);
        $bytes[$at] = $bytes[$at] === 'A' ? 'B' : 'A';
        file_put_contents($this->root . '/in/tampered.zip', $bytes);
        self::assertNotSame(VerifyFileResult::OK, $archiver->verifyFile($this->root . '/in/tampered.zip', ArchivePassword::fromString(self::PHRASE))->status);
        self::assertSame(VerifyFileResult::WRONG_PASSWORD, $archiver->verifyFile($zip->getAbsolutePath(), ArchivePassword::fromString('another-phrase-0000000'))->status);

        // age: a tampered file and a hostile work factor are integrity / format failures.
        $age = EngineFixtures::fakeAge($this->root . '/bin');
        file_put_contents($this->root . '/bin/plain', 'x');
        $encryptor = new AgeBinaryEncryptor($age);
        $encryptor->encrypt($this->root . '/bin/plain', $this->root . '/bin/enc.age', ArchivePassword::fromString(self::PHRASE));
        foreach (['echo "age: error: chunk 3: failed to decrypt and authenticate payload chunk" >&2; exit 1' => CorruptArchiveException::CHECKSUM, 'echo "age: error: scrypt work factor too large: 30" >&2; exit 1' => 0] as $body => $code) {
            $broken = EngineFixtures::script($this->root . '/bin', 'age', 'PATH=/usr/bin:/bin; if [ "$1" = "--version" ]; then echo v1.3.1; exit 0; fi; cat <&3 >/dev/null; ' . $body);
            try {
                (new AgeBinaryEncryptor($broken))->decrypt($this->root . '/bin/enc.age', $this->root . '/bin/out', ArchivePassword::fromString(self::PHRASE));
                self::fail('Expected CorruptArchiveException');
            } catch (CorruptArchiveException $e) {
                self::assertSame($code, $e->getCode());
            }
        }
    }

    private function requireZipAes(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ext-zip with AES support is needed.');
        }
    }

    private function archiver(?BackupSigner $signer = null, ?MockClock $clock = null): BackupArchiver
    {
        return new BackupArchiver($this->project, $this->root . '/storage', ['public'], [], null, 60, clock: $clock ?? new MockClock(), workDir: $this->root . '/work', signer: $signer);
    }

    private function put(InMemoryRemoteTarget $target, string $key, string $data): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $data);
        rewind($stream);
        $target->upload($stream, $key, strlen($data), []);
    }

    /**
     * Offset of a byte inside the first encrypted payload (not a header field the reader may ignore).
     */
    private static function middleOfFirstEntryData(string $bytes): int
    {
        $offset = 0;
        while (substr($bytes, $offset, 4) === "PK\x03\x04") {
            /** @var array{c: int} $size */
            $size = unpack('Vc', substr($bytes, $offset + 18, 4));
            /** @var array{n: int, e: int} $lengths */
            $lengths = unpack('vn/ve', substr($bytes, $offset + 26, 4));
            $data    = $offset + 30 + $lengths['n'] + $lengths['e'];
            // AES payload: salt (16) + verifier (2) + data + HMAC (10); aim at the data.
            if ($size['c'] > 28) {
                return $data + 18 + intdiv($size['c'] - 28, 2);
            }
            $offset = $data + $size['c'];
        }

        self::fail('No encrypted entry with a known size found.');
    }
}
