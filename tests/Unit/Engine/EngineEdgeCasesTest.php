<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Crypto\AgeBinaryEncryptor;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\TableStatsCollector;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Event\RemoteBackupPrunedEvent;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Remote\InMemoryRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Remote\RemoteObject;
use Nowo\SiteBackupBundle\Remote\VerifyMode;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function file_put_contents;
use function fopen;
use function fwrite;
use function gzencode;
use function rewind;
use function str_repeat;
use function strlen;

final class EngineEdgeCasesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = EngineFixtures::tempDir('sbb-edge-');
        (new Filesystem())->mkdir([$this->root . '/project/config', $this->root . '/bin']);
        file_put_contents($this->root . '/project/config/app.yaml', 'a: b');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testPublisherFailureBranches(): void
    {
        $archiver  = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60);
        $artifact  = $archiver->create();
        $publisher = new RemoteBackupPublisher();

        $cases = [
            'sidecar upload' => new class extends ForwardingTarget {
                public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
                {
                    if (str_ends_with($key, '.json')) {
                        throw new RuntimeException('denied');
                    }

                    return parent::upload($stream, $key, $sizeBytes, $metadata);
                }
            },
            'head' => new class extends ForwardingTarget {
                public function head(string $key): ?RemoteObject
                {
                    throw new RuntimeException('head denied');
                }
            },
            'own exception' => new class extends ForwardingTarget {
                public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
                {
                    throw new RemoteTargetException('as is');
                }
            },
        ];
        foreach ($cases as $name => $target) {
            try {
                $publisher->publish($artifact, $target, '', 'a.tar.gz');
                self::fail('Expected RemoteTargetException: ' . $name);
            } catch (RemoteTargetException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        $unreadable = new class extends ForwardingTarget {
            public function openRead(string $key)
            {
                if ($key !== '') {
                    throw new RuntimeException('read denied');
                }

                return parent::openRead($key);
            }
        };
        $this->expectException(RemoteTargetException::class);
        $publisher->publish($artifact, $unreadable, '', 'a.tar.gz', [], VerifyMode::FullRehash);
    }

    public function testPublisherCatalogueSkipsBrokenSidecarsAndRecordsKeyIds(): void
    {
        $target    = new InMemoryRemoteTarget();
        $publisher = new RemoteBackupPublisher(null, new MockClock());
        $archiver  = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60);
        $artifact  = $archiver->create();
        $publisher->publish($artifact, $target, 'p/', 'p/real.tar.gz');

        $this->put($target, 'p/lonely.tar.gz.backup.json', '{"id":"lonely","created_at":"2026-01-01T00:00:00+00:00"}');
        $this->put($target, 'p/big.tar.gz', 'x');
        $this->put($target, 'p/big.tar.gz.backup.json', str_repeat(' ', 70 * 1024));
        $this->put($target, 'p/noid.tar.gz', 'x');
        $this->put($target, 'p/noid.tar.gz.backup.json', '{"created_at":"2026-01-01T00:00:00+00:00"}');
        $this->put($target, 'p/baddate.tar.gz', 'x');
        $this->put($target, 'p/baddate.tar.gz.backup.json', '{"id":"bd","created_at":"yesterday"}');

        $events   = [];
        $observed = new RemoteBackupPublisher(new class($events) implements EventDispatcherInterface {
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
        }, new MockClock());
        self::assertSame([], $observed->prune($target, 'p/', new RetentionPolicy(keepLast: 0), $artifact->getId())->deletedKeys);
        $pruned = $events[0] ?? null;
        self::assertInstanceOf(RemoteBackupPrunedEvent::class, $pruned);
        self::assertSame('p/', $pruned->getPrefix());
        self::assertSame([], $pruned->getReport()->deletedKeys);

        if (class_exists(ZipArchive::class) && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            $zip    = $archiver->create((new BackupOptions(format: ArchiveFormat::Zip))->withPassword(ArchivePassword::fromString('canary-edge-phrase-01'), 'k-9'));
            $stored = $publisher->publish($zip, $target, 'p/', 'p/zip.zip');
            self::assertSame('k-9', $stored->metadata['nowo-key-id']);
        }
    }

    public function testImporterAndCollectorFailureBranches(): void
    {
        $slow    = EngineFixtures::script($this->root . '/bin', 'slow-mysql', 'sleep 30');
        $failing = EngineFixtures::script($this->root . '/bin', 'failing-mysql', 'echo "ERROR 1142 (42000) at line 1: denied" >&2; exit 1');
        $names   = EngineFixtures::script($this->root . '/bin', 'names-mysql', 'case "$*" in *information_schema*) printf "bad-name\n";; esac');

        $cases = [
            static fn () => (new DumpImporter('sqlite:///x'))->databaseName(),
            static fn () => (new DumpImporter('mysql://u@h/d'))->createSchema('bad name'),
            static fn () => (new DumpImporter('sqlite:///x'))->dropSchema('ok_name'),
            static fn () => (new DumpImporter('mysql://u@h/d', $slow, false, 1))->createSchema('ok_name'),
            static fn () => (new DumpImporter('mysql://u@h/d', $failing))->dropSchema('ok_name'),
            static fn () => (new TableStatsCollector('sqlite:///x'))->collect(),
            static fn () => (new TableStatsCollector('mysql://u@h/d', $slow, false, 1))->collect(),
            static fn () => (new TableStatsCollector('mysql://u@h/d', $failing))->collect(),
        ];
        foreach ($cases as $i => $case) {
            try {
                $case();
                self::fail('Expected DumpFailedException #' . $i);
            } catch (DumpFailedException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame([], (new TableStatsCollector('mysql://u@h/d', $names))->collect(), 'unsafe names are ignored');

        $this->expectException(DumpFailedException::class);
        (new TableStatsCollector('mysql://u@h/d', $names))->collect('bad-schema');
    }

    public function testUnsafeTarListingsAndExtractFailures(): void
    {
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60);
        $tar      = $archiver->create();

        $listFails = EngineFixtures::script($this->root . '/bin', 'tar-list-fails', 'exit 2');
        $escapes   = EngineFixtures::script($this->root . '/bin', 'tar-escape', 'case "$1" in -tvzf) echo "-rw-r--r-- u/g 1 2026-01-01 00:00 ./a";; -tzf) echo "./../evil";; esac');
        $noExtract = EngineFixtures::script($this->root . '/bin', 'tar-noextract', 'case "$1" in -tvzf) echo "-rw-r--r-- u/g 1 2026-01-01 00:00 ./a";; -tzf) echo "./a";; -xzf) echo "cannot extract" >&2; exit 2;; esac');

        foreach ([$listFails, $escapes] as $tarBinary) {
            try {
                (new BackupArchiver($this->root . '/project', $this->root . '/other', ['config'], [], null, 60, tarBinary: $tarBinary))->import($tar->getAbsolutePath());
                self::fail('Expected CorruptArchiveException');
            } catch (CorruptArchiveException) {
                self::addToAssertionCount(1);
            }
        }

        $result = (new BackupArchiver($this->root . '/project', $this->root . '/other', ['config'], [], null, 60, tarBinary: $noExtract))->verifyFile($tar->getAbsolutePath());
        self::assertSame(VerifyFileResult::UNSUPPORTED, $result->status);
    }

    public function testAgeContainerHoldingAZipIsRefusedAndSqlGzVerifyTicks(): void
    {
        $age      = EngineFixtures::fakeAge($this->root . '/bin');
        $archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], 'echo "SELECT 1;"', 60, encryptor: new AgeBinaryEncryptor($age));
        $phrase   = ArchivePassword::fromString('canary-edge-phrase-01');

        if (class_exists(ZipArchive::class)) {
            $zip = $archiver->create(new BackupOptions(format: ArchiveFormat::Zip));
            (new AgeBinaryEncryptor($age))->encrypt($zip->getAbsolutePath(), $this->root . '/zip.age', $phrase);
            try {
                $archiver->import($this->root . '/zip.age', $phrase);
                self::fail('Expected CorruptArchiveException');
            } catch (CorruptArchiveException) {
            }
        }

        $sql   = $archiver->create(new BackupOptions(includeFiles: false, format: ArchiveFormat::SqlGz));
        $ticks = 0;
        self::assertTrue($archiver->verifyIntegrity($sql, static function () use (&$ticks): void {
            ++$ticks;
        })['ok']);
        self::assertGreaterThan(0, $ticks);

        // A plain gzip that is not SQL text still imports as sql.gz.
        file_put_contents($this->root . '/x.gz', (string) gzencode('binary?'));
        self::assertSame(ArchiveFormat::SqlGz, BackupArchiver::detectFormat($this->root . '/x.gz'));
    }

    private function put(InMemoryRemoteTarget $target, string $key, string $data): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $data);
        rewind($stream);
        $target->upload($stream, $key, strlen($data), []);
    }
}
