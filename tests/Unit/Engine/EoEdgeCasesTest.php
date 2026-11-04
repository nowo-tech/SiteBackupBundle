<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Command\ImportArchiveCommand;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Remote\RemoteObject;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function function_exists;

final class EoEdgeCasesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = EngineFixtures::tempDir('sbb-eo2-');
        (new Filesystem())->mkdir([$this->root . '/project/public', $this->root . '/bin']);
        file_put_contents($this->root . '/project/public/index.php', '<?php');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testImportCommandWithSidecarAndSignatureFlags(): void
    {
        $pair     = BackupSigner::generateKeyPair();
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60, signer: new BackupSigner($pair['secret_key'], 'k1'));
        $manager  = new SiteBackupManager($archiver, new RestoreOrchestrator($this->root . '/project', $archiver, new MemoryRestoreProgressStorage(), []), new MemoryHistoryStorage());
        $artifact = $archiver->create();
        $tester   = new CommandTester(new ImportArchiveCommand($manager));
        file_put_contents($this->root . '/bad.json', '{');

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $artifact->getAbsolutePath()]), 'unsigned import refused');
        self::assertSame(Command::INVALID, $tester->execute(['file' => $artifact->getAbsolutePath(), '--sidecar' => $this->root . '/bad.json']));
        self::assertSame(Command::INVALID, $tester->execute(['file' => $artifact->getAbsolutePath(), '--sidecar' => $this->root . '/missing.json']));
        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $artifact->getAbsolutePath(), '--sidecar' => $this->root . '/storage/' . $artifact->getId() . '.meta.json', '--keep-after-restore' => true]));
        self::assertStringContainsString('then delete it', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $artifact->getAbsolutePath(), '--no-require-signature' => true]));
        self::assertStringContainsString('deleted after the restore', $tester->getDisplay());
    }

    public function testPruneIgnoresSidecarsWhoseMetadataDisagrees(): void
    {
        $clock     = new MockClock('2026-11-04 03:00:00');
        $archiver  = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60, clock: $clock);
        $publisher = new RemoteBackupPublisher(null, $clock);
        $target    = new class extends ForwardingTarget {
            public function list(string $prefix): iterable
            {
                foreach (parent::list($prefix) as $object) {
                    // A bucket writer rewrote the metadata of every archive object.
                    yield str_ends_with($object->key, '.json') ? $object : new RemoteObject($object->key, $object->sizeBytes, $object->lastModified, ['nowo-backup-id' => '20000101-000000-ffffffff', 'nowo-sha256' => 'x']);
                }
            }
        };
        $first = $archiver->create();
        $publisher->publish($first, $target, '', $first->getId() . '.tar.gz');
        $clock->sleep(60);
        $second = $archiver->create();
        $publisher->publish($second, $target, '', $second->getId() . '.tar.gz');

        self::assertSame([], $publisher->prune($target, '', new RetentionPolicy(keepLast: 0), $second->getId())->deletedKeys);

        $shaOnly = new class extends ForwardingTarget {
            public function list(string $prefix): iterable
            {
                foreach (parent::list($prefix) as $object) {
                    $meta = $object->metadata;
                    if (isset($meta['nowo-sha256'])) {
                        $meta['nowo-sha256'] = 'tampered';
                    }
                    yield new RemoteObject($object->key, $object->sizeBytes, $object->lastModified, $meta);
                }
            }
        };
        $publisher->publish($first, $shaOnly, '', $first->getId() . '.tar.gz');
        $publisher->publish($second, $shaOnly, '', $second->getId() . '.tar.gz');
        self::assertSame([], $publisher->prune($shaOnly, '', new RetentionPolicy(keepLast: 0), $second->getId())->deletedKeys);
    }

    public function testHeadFailingAfterTheUploadIsWrapped(): void
    {
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60);
        $target   = new class extends ForwardingTarget {
            private int $heads = 0;

            public function head(string $key): ?RemoteObject
            {
                if (++$this->heads > 1) {
                    throw new RuntimeException('head denied');
                }

                return parent::head($key);
            }
        };

        $this->expectException(RemoteTargetException::class);
        (new RemoteBackupPublisher())->publish($archiver->create(), $target, '', 'a.tar.gz');
    }

    public function testGarbageThatLooksLikeZipOrTarIsRefused(): void
    {
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60);
        file_put_contents($this->root . '/fake.zip', "PK\x03\x04garbage");
        self::assertSame(VerifyFileResult::UNSUPPORTED, $archiver->verifyFile($this->root . '/fake.zip')->status);

        // The listing passes (stand-in tar) but the stream is not gzip.
        $tar = EngineFixtures::script($this->root . '/bin', 'tar-ok', 'case "$1" in -tvzf) echo "-rw-r--r-- u/g 1 2026-01-01 00:00 ./a";; -tzf) echo "./a";; esac');
        file_put_contents($this->root . '/broken.tar.gz', "\x1f\x8b" . 'not really gzip');
        $artifact = new BackupArtifact('b', 'broken.tar.gz', $this->root . '/broken.tar.gz', new DateTimeImmutable(), 1, (string) hash_file('sha256', $this->root . '/broken.tar.gz'));
        $errors   = (new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60, tarBinary: $tar))->verifyIntegrity($artifact)['errors'];
        self::assertStringContainsString('not valid gzip', implode(' ', $errors));
    }

    public function testForeignOwnedWorkDirIsRefused(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs root to give the directory to another user.');
        }
        $work = $this->root . '/foreign-work';
        mkdir($work, 0o700);
        chown($work, 65534);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owned by the user');
        (new BackupArchiver($this->root . '/project', $this->root . '/storage', ['public'], [], null, 60, workDir: $work))->create();
    }
}
