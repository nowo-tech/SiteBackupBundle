<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Command\SigningKeygenCommand;
use Nowo\SiteBackupBundle\Command\VerifyBackupCommand;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Remote\InMemoryRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function gzencode;
use function json_decode;
use function json_encode;
use function stream_get_contents;

final class SigningVerifyFileTest extends TestCase
{
    private const PHRASE = 'canary-verify-phrase-01';

    private string $root;
    private BackupSigner $signer;
    private string $publicKey;

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('ext-sodium');
        }
        $this->root = EngineFixtures::tempDir('sbb-sign-');
        (new Filesystem())->mkdir($this->root . '/project/config');
        file_put_contents($this->root . '/project/config/app.yaml', 'a: b');
        $pair            = BackupSigner::generateKeyPair();
        $this->publicKey = $pair['public_key'];
        $this->signer    = new BackupSigner($pair['secret_key'], 'k1');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testSignerKeysAndMessages(): void
    {
        $fields = ['id' => 'a', 'sha256' => 'x', 'size' => 1];
        $sig    = $this->signer->sign($fields);

        self::assertTrue($this->signer->canSign());
        self::assertSame('k1', $sig['signing_key_id']);
        self::assertTrue($this->signer->verify($fields, $sig['signature'], 'k1'));
        self::assertFalse($this->signer->verify(['size' => 1, 'sha256' => 'y', 'id' => 'a'], $sig['signature'], 'k1'));
        self::assertFalse($this->signer->verify($fields, $sig['signature'], 'other'));
        self::assertFalse($this->signer->verify($fields, 'not-base64!', 'k1'));

        $verifier = new BackupSigner(null, 'default', ['k1' => $this->publicKey]);
        self::assertFalse($verifier->canSign());
        self::assertTrue($verifier->canVerify());
        self::assertTrue($verifier->verify($fields, $sig['signature'], 'k1'));
        self::assertFalse((new BackupSigner())->canVerify());

        // A 64-byte secret key works too.
        $full = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        self::assertTrue((new BackupSigner($full))->canSign());

        foreach ([
            static fn () => new BackupSigner('short'),
            static fn () => new BackupSigner(null, 'bad id'),
            static fn () => new BackupSigner(null, 'k', ['k' => 'nope']),
            static fn () => (new BackupSigner())->sign([]),
        ] as $bad) {
            try {
                $bad();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $tester = new CommandTester(new SigningKeygenCommand());
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('SITE_BACKUP_SIGNING_KEY=', $tester->getDisplay());
    }

    public function testSignedBackupsVerifyLocallyRemotelyAndAsFiles(): void
    {
        $archiver = $this->archiver($this->signer);
        $artifact = $archiver->create();

        self::assertSame('k1', $artifact->getMeta()['signing_key_id']);
        self::assertTrue($archiver->verifyIntegrity($artifact)['ok']);

        // Tampered sidecar field → signature invalid.
        $meta           = $artifact->getMeta();
        $meta['key_id'] = 'forged';
        $forged         = new BackupArtifact($artifact->getId(), $artifact->getFilename(), $artifact->getAbsolutePath(), $artifact->getCreatedAt(), $artifact->getSizeBytes(), $artifact->getArchiveSha256(), $artifact->getChecksums(), $meta);
        self::assertSame(['Signature invalid or signing key unknown.'], $archiver->verifyIntegrity($forged)['errors']);

        // The remote sidecar carries the signature; verify --file checks it.
        $target = new InMemoryRemoteTarget();
        (new RemoteBackupPublisher())->publish($artifact, $target, '', 'a.tar.gz');
        $sidecar = json_decode((string) stream_get_contents($target->openRead('a.tar.gz.backup.json')), true);
        self::assertIsArray($sidecar);
        self::assertSame('k1', $sidecar['signing_key_id']);
        $verifier = $this->archiver(new BackupSigner(null, 'default', ['k1' => $this->publicKey]));
        self::assertTrue($verifier->verifyFile($artifact->getAbsolutePath(), null, null, $sidecar)->isOk());
        $sidecar['created_at'] = '2000-01-01T00:00:00+00:00';
        self::assertSame(VerifyFileResult::SIGNATURE, $verifier->verifyFile($artifact->getAbsolutePath(), null, null, $sidecar)->status);

        // The local .meta.json works as a sidecar too.
        $local = json_decode((string) file_get_contents($this->root . '/storage/' . $artifact->getId() . '.meta.json'), true);
        self::assertIsArray($local);
        self::assertTrue($verifier->verifyFile($artifact->getAbsolutePath(), null, null, $local)->isOk());
    }

    public function testVerifyFileStatuses(): void
    {
        $archiver = $this->archiver(null);
        $tar      = $archiver->create();

        $ok = $archiver->verifyFile($tar->getAbsolutePath());
        self::assertTrue($ok->isOk());
        self::assertSame(ArchiveFormat::TarGz, $ok->format);
        self::assertSame(VerifyFileResult::INTEGRITY, $archiver->verifyFile($tar->getAbsolutePath(), null, str_repeat('0', 64))->status);
        self::assertSame(VerifyFileResult::UNSUPPORTED, $archiver->verifyFile($this->root . '/missing')->status);
        file_put_contents($this->root . '/garbage', 'garbage');
        self::assertSame(VerifyFileResult::UNSUPPORTED, $archiver->verifyFile($this->root . '/garbage')->status);

        // MANIFEST mismatch → integrity.
        $payload = $this->root . '/payload';
        (new Filesystem())->mkdir($payload);
        file_put_contents($payload . '/a.txt', 'a');
        file_put_contents($payload . '/MANIFEST.json', '{"checksums":{"a.txt":"0000"}}');
        (new Process(['tar', '-czf', $this->root . '/bad.tar.gz', '-C', $payload, '.']))->mustRun();
        self::assertSame(VerifyFileResult::INTEGRITY, $archiver->verifyFile($this->root . '/bad.tar.gz')->status);

        // sql.gz with a sidecar checksum.
        file_put_contents($this->root . '/d.sql.gz', (string) gzencode('SELECT 1;'));
        self::assertTrue($archiver->verifyFile($this->root . '/d.sql.gz', null, null, ['plaintext_sha256' => hash('sha256', 'SELECT 1;')])->isOk());
        self::assertSame(VerifyFileResult::INTEGRITY, $archiver->verifyFile($this->root . '/d.sql.gz', null, null, ['plaintext_sha256' => 'x'])->status);

        if (class_exists(ZipArchive::class) && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            $zip = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword(ArchivePassword::fromString(self::PHRASE)));
            self::assertSame(VerifyFileResult::WRONG_PASSWORD, $archiver->verifyFile($zip->getAbsolutePath())->status);
            $good = $archiver->verifyFile($zip->getAbsolutePath(), ArchivePassword::fromString(self::PHRASE));
            self::assertTrue($good->isOk());
            self::assertSame(BackupArchiver::ENCRYPTION_ZIP_AES, $good->encryption);
        }
    }

    public function testVerifyFileCommand(): void
    {
        $archiver = $this->archiver(null);
        $tar      = $archiver->create();
        $manager  = new SiteBackupManager($archiver, new RestoreOrchestrator($this->root . '/project', $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage());
        $tester   = new CommandTester(new VerifyBackupCommand($manager));
        file_put_contents($this->root . '/sidecar.json', (string) json_encode(['sha256' => $tar->getArchiveSha256()]));
        file_put_contents($this->root . '/not-json', '{');

        self::assertSame(0, $tester->execute(['--file' => $tar->getAbsolutePath(), '--sidecar' => $this->root . '/sidecar.json']));
        self::assertSame(1, $tester->execute(['--file' => $tar->getAbsolutePath(), '--sha256' => str_repeat('a', 64)]));
        self::assertSame(3, $tester->execute(['--file' => $this->root . '/missing']));
        self::assertSame(Command::INVALID, $tester->execute(['--file' => $tar->getAbsolutePath(), '--sha256' => 'xyz']));
        self::assertSame(Command::INVALID, $tester->execute(['--file' => $tar->getAbsolutePath(), '--sidecar' => $this->root . '/not-json']));
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $tar->getId()]));
    }

    private function archiver(?BackupSigner $signer): BackupArchiver
    {
        return new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60, workDir: $this->root . '/work', signer: $signer);
    }
}
