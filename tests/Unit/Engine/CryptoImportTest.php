<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use InvalidArgumentException;
use LogicException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Event\BackupImportedEvent;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

use function file_get_contents;
use function file_put_contents;
use function gzencode;
use function json_encode;
use function print_r;
use function serialize;
use function str_repeat;
use function symlink;
use function var_export;

final class CryptoImportTest extends TestCase
{
    private const PHRASE = 'canary-archive-phrase-01';

    private string $root;
    private string $project;
    private string $storage;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ext-zip with AES support is needed.');
        }
        $this->root    = EngineFixtures::tempDir('sbb-crypto-');
        $this->project = $this->root . '/project';
        $this->storage = $this->root . '/storage';
        (new Filesystem())->mkdir([$this->project . '/config', $this->project . '/public']);
        file_put_contents($this->project . '/config/app.yaml', str_repeat("a: b\n", 50));
        file_put_contents($this->project . '/public/index.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testPasswordValueObject(): void
    {
        $password = ArchivePassword::fromString(self::PHRASE);

        self::assertSame(self::PHRASE, $password->reveal());
        self::assertSame('***', (string) $password);
        self::assertStringNotContainsString('canary-archive', print_r($password, true));
        self::assertStringNotContainsString('canary-archive', var_export(['p' => $password->__debugInfo()], true));
        self::assertGreaterThanOrEqual(ArchivePassword::WEAK_ZIP_BITS, $password->estimatedBits());
        self::assertLessThan(ArchivePassword::WEAK_ZIP_BITS, ArchivePassword::fromString('aaaaaaaaaaaaaaaa')->estimatedBits());

        $password->wipe();
        try {
            $password->reveal();
            self::fail('Expected LogicException');
        } catch (LogicException) {
        }
        $password->wipe();

        foreach (['short', str_repeat('x', 1025), "0123456789abcdef\0"] as $bad) {
            try {
                ArchivePassword::fromString($bad);
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString($bad, $e->getMessage());
            }
        }
    }

    public function testPasswordCannotBeSerialised(): void
    {
        $this->expectException(LogicException::class);
        serialize(ArchivePassword::fromString(self::PHRASE));
    }

    public function testPasswordCannotBeUnserialised(): void
    {
        $this->expectException(LogicException::class);
        unserialize('O:43:"Nowo\SiteBackupBundle\Model\ArchivePassword":0:{}');
    }

    public function testKeyIdNeedsAPassword(): void
    {
        $this->expectException(InvalidBackupOptionsException::class);
        new BackupOptions(keyId: 'bkp_1');
    }

    public function testEncryptedZipRoundTripAndImport(): void
    {
        $archiver = $this->archiver();
        $options  = (new BackupOptions(format: ArchiveFormat::Zip, label: 'nightly'))->withPassword(ArchivePassword::fromString(self::PHRASE), 'bkp_ABCD1234');
        $artifact = $archiver->create($options);

        self::assertTrue($artifact->isEncrypted());
        self::assertSame('bkp_ABCD1234', $artifact->getMeta()['key_id']);
        self::assertSame(BackupArchiver::ENCRYPTION_ZIP_AES, $artifact->getMeta()['encryption']);
        self::assertTrue($options->toLoggableArray()['encrypted']);
        $sidecar = (string) file_get_contents($this->storage . '/' . $artifact->getId() . '.meta.json');
        self::assertStringNotContainsString(self::PHRASE, $sidecar);
        self::assertStringNotContainsString(self::PHRASE, (string) file_get_contents($artifact->getAbsolutePath()));

        // Every entry is AES-256; nothing readable without the password.
        $zip = new ZipArchive();
        $zip->open($artifact->getAbsolutePath());
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            self::assertSame(ZipArchive::EM_AES_256, $zip->statIndex($i)['encryption_method'] ?? null);
        }
        $zip->close();

        self::assertSame(['This archive is encrypted: a password is needed.'], $archiver->verifyIntegrity($artifact)['errors']);
        self::assertSame(['Unable to decrypt the archive: wrong password or corrupt data.'], $archiver->verifyIntegrity($artifact, null, ArchivePassword::fromString('wrong-password-0000000'))['errors']);
        self::assertTrue($archiver->verifyIntegrity($artifact, null, ArchivePassword::fromString(self::PHRASE))['ok']);

        // Import into another instance.
        $other    = new BackupArchiver($this->project, $this->root . '/other-storage', ['config'], [], null, 60, workDir: $this->root . '/other-work');
        $imported = $other->import($artifact->getAbsolutePath(), ArchivePassword::fromString(self::PHRASE), 'fetch');

        self::assertSame(ArchiveFormat::TarGz, $imported->getFormat());
        self::assertFalse($imported->isEncrypted());
        self::assertSame('nightly', $imported->getLabel());
        self::assertSame('fetch', $imported->getCreatedBy());
        self::assertSame(['zip', true, 'bkp_ABCD1234', $artifact->getId()], [
            $imported->getMeta()['imported_from']['format'],
            $imported->getMeta()['imported_from']['encrypted'],
            $imported->getMeta()['imported_from']['key_id'],
            $imported->getMeta()['imported_from']['original_id'],
        ]);
        self::assertSame($artifact->getChecksums(), $imported->getChecksums());
        self::assertTrue($other->verifyIntegrity($imported)['ok']);
        self::assertSame(0o600, fileperms($imported->getAbsolutePath()) & 0o777);

        try {
            $other->import($artifact->getAbsolutePath(), ArchivePassword::fromString('wrong-password-0000000'));
            self::fail('Expected WrongPasswordException');
        } catch (WrongPasswordException $e) {
            self::assertStringNotContainsString('wrong-password', $e->getMessage());
        }
    }

    public function testWeakZipPasswordWarns(): void
    {
        $artifact = $this->archiver()->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword(ArchivePassword::fromString('aaaaaaaaaaaaaaaa')));

        self::assertStringContainsString('looks weak', $artifact->getWarnings()[0]);
    }

    public function testPasswordOnTarGzIsRefusedUntilAgeExists(): void
    {
        $this->expectException(FormatUnavailableException::class);
        $this->archiver()->create((new BackupOptions())->withPassword(ArchivePassword::fromString(self::PHRASE)));
    }

    public function testImportOfTarGzAndSqlGzAndDroppedSecrets(): void
    {
        $archiver = $this->archiver();
        $tar      = $archiver->create(new BackupOptions());
        $other    = new BackupArchiver($this->project, $this->root . '/other', ['config'], [], null, 60);

        $imported = $other->import($tar->getAbsolutePath());
        self::assertSame('tar.gz', $imported->getMeta()['imported_from']['format']);
        self::assertSame('files', $imported->getContent());

        $sqlGz = $this->root . '/dump.sql.gz';
        file_put_contents($sqlGz, (string) gzencode("CREATE TABLE t (id INT);\n"));
        $fromSql = $other->import($sqlGz);
        self::assertSame('database', $fromSql->getContent());
        self::assertSame('sql.gz', $fromSql->getMeta()['imported_from']['format']);
        self::assertTrue($other->verifyIntegrity($fromSql)['ok']);

        // A 1.27-style archive with .env inside: the secret is dropped, the rest kept.
        $payload = $this->root . '/legacy';
        (new Filesystem())->mkdir($payload . '/config');
        file_put_contents($payload . '/.env', 'CANARY-env');
        file_put_contents($payload . '/config/a.yaml', 'a: 1');
        file_put_contents($payload . '/MANIFEST.json', (string) json_encode(['version' => 1, 'id' => 'legacy', 'checksums' => ['.env' => hash_file('sha256', $payload . '/.env'), 'config/a.yaml' => hash_file('sha256', $payload . '/config/a.yaml')], 'meta' => ['label' => 'old']]));
        (new Process(['tar', '-czf', $this->root . '/legacy.tar.gz', '-C', $payload, '.']))->mustRun();
        $legacy = $other->import($this->root . '/legacy.tar.gz');
        self::assertSame(['config/a.yaml'], array_keys($legacy->getChecksums()));
        self::assertSame(['1 secret path(s) dropped from the imported archive.'], $legacy->getWarnings());
    }

    public function testImportRefusesUnsafeOrBrokenArchives(): void
    {
        $other = new BackupArchiver($this->project, $this->root . '/other', ['config'], [], null, 60);

        // Symlink inside a tar.
        $payload = $this->root . '/evil';
        (new Filesystem())->mkdir($payload);
        symlink('/etc/passwd', $payload . '/link');
        file_put_contents($payload . '/MANIFEST.json', '{"checksums":{}}');
        (new Process(['tar', '-czf', $this->root . '/link.tar.gz', '-C', $payload, '.']))->mustRun();

        // Tampered manifest.
        $bad = $this->root . '/bad';
        (new Filesystem())->mkdir($bad);
        file_put_contents($bad . '/a.txt', 'x');
        file_put_contents($bad . '/MANIFEST.json', '{"checksums":{"a.txt":"0000"}}');
        (new Process(['tar', '-czf', $this->root . '/bad.tar.gz', '-C', $bad, '.']))->mustRun();

        // No manifest / invalid manifest / manifest without checksums.
        $none = $this->root . '/none';
        (new Filesystem())->mkdir($none);
        file_put_contents($none . '/a.txt', 'x');
        (new Process(['tar', '-czf', $this->root . '/none.tar.gz', '-C', $none, '.']))->mustRun();
        file_put_contents($none . '/MANIFEST.json', '{bad');
        (new Process(['tar', '-czf', $this->root . '/invalid.tar.gz', '-C', $none, '.']))->mustRun();
        file_put_contents($none . '/MANIFEST.json', '{"id":"x"}');
        (new Process(['tar', '-czf', $this->root . '/nochecksums.tar.gz', '-C', $none, '.']))->mustRun();

        // ZipCrypto entries.
        $legacyZip = $this->root . '/zipcrypto.zip';
        $zip       = new ZipArchive();
        $zip->open($legacyZip, ZipArchive::CREATE);
        $zip->addFromString('MANIFEST.json', '{}');
        $zip->setEncryptionName('MANIFEST.json', ZipArchive::EM_TRAD_PKWARE, 'canary-zip-0000000');
        $zip->close();

        file_put_contents($this->root . '/age.age', "age-encryption.org/v1\n-> scrypt x 18\n");
        file_put_contents($this->root . '/garbage.bin', 'not an archive');
        file_put_contents($this->root . '/badgzip.gz', "\x1f\x8b" . 'broken');

        foreach ([
            'link.tar.gz'        => CorruptArchiveException::class,
            'bad.tar.gz'         => CorruptArchiveException::class,
            'none.tar.gz'        => CorruptArchiveException::class,
            'invalid.tar.gz'     => CorruptArchiveException::class,
            'nochecksums.tar.gz' => CorruptArchiveException::class,
            'zipcrypto.zip'      => CorruptArchiveException::class,
            'age.age'            => FormatUnavailableException::class,
            'garbage.bin'        => CorruptArchiveException::class,
            'badgzip.gz'         => CorruptArchiveException::class,
            'missing.zip'        => CorruptArchiveException::class,
        ] as $file => $expected) {
            try {
                $other->import($this->root . '/' . $file);
                self::fail('Expected ' . $expected . ' for ' . $file);
            } catch (Throwable $e) {
                self::assertInstanceOf($expected, $e, $file . ': ' . $e->getMessage());
            }
        }
        self::assertSame([], glob($this->root . '/other/*') ?: [], 'nothing is stored after a refused import');
    }

    public function testManagerImportDispatchesAndLogs(): void
    {
        $archiver = $this->archiver();
        $source   = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword(ArchivePassword::fromString(self::PHRASE), 'k1'));
        $events   = [];
        $logger   = new RecordingLogger();
        $manager  = new SiteBackupManager(
            archiver: $archiver,
            restoreOrchestrator: new RestoreOrchestrator($this->project, $archiver, new MemoryRestoreProgressStorage(), []),
            historyStorage: new MemoryHistoryStorage(),
            eventDispatcher: new class($events) implements EventDispatcherInterface {
                /** @var list<object> */
                private array $events;

                /** @param list<object> $events */
                public function __construct(array &$events)
                {
                    $this->events = &$events;
                }

                public function dispatch(object $event): object
                {
                    $this->events[] = $event;

                    return $event;
                }

                /** @return list<object> */
                public function all(): array
                {
                    return $this->events;
                }
            },
            logger: $logger,
        );

        $imported = $manager->importArchive($source->getAbsolutePath(), ArchivePassword::fromString(self::PHRASE), 'ops');
        $event    = $events[0] ?? null;
        self::assertInstanceOf(BackupImportedEvent::class, $event);
        self::assertSame('zip', $event->getSourceFormat());
        self::assertTrue($event->wasEncrypted());
        self::assertSame('ops', $event->getActor());
        self::assertSame($imported, $event->getArtifact());
        self::assertTrue($manager->verifyBackup($source->getId(), ArchivePassword::fromString(self::PHRASE))['ok']);

        try {
            $manager->importArchive($this->root . '/missing.zip');
            self::fail('Expected CorruptArchiveException');
        } catch (CorruptArchiveException) {
        }
        self::assertStringNotContainsString(self::PHRASE, (string) json_encode($logger->records));
        self::assertSame('error SiteBackup: import failed.', $logger->lines()[1]);
    }

    public function testRedactorMasksSignedUrls(): void
    {
        $message = SecretRedactor::redact('GET https://b.s3/x?X-Amz-Credential=AKIA/x&X-Amz-Signature=abc123&token=t0k failed');

        self::assertStringNotContainsString('abc123', $message);
        self::assertStringNotContainsString('AKIA', $message);
        self::assertStringNotContainsString('t0k', $message);
    }

    private function archiver(): BackupArchiver
    {
        return new BackupArchiver($this->project, $this->storage, ['config', 'public'], [], null, 60, workDir: $this->root . '/work');
    }
}
