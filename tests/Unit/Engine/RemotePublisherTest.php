<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Event\RemoteBackupPrunedEvent;
use Nowo\SiteBackupBundle\Event\RemoteBackupUploadedEvent;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Remote\BackupRemoteTargetInterface;
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

use function count;
use function file_put_contents;
use function iterator_to_array;
use function json_decode;
use function stream_get_contents;
use function strlen;

final class RemotePublisherTest extends TestCase
{
    private string $root;
    private MockClock $clock;
    private BackupArchiver $archiver;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->root = EngineFixtures::tempDir('sbb-remote-');
        // Relative to the real time: InMemoryRemoteTarget stamps uploads with the wall clock, and orphans() compares
        // those stamps with this clock (a fixed date turned the orphan assertions into a time bomb on 2026-11-08).
        $this->clock = new MockClock(new DateTimeImmutable('-1 day'));
        (new Filesystem())->mkdir($this->root . '/project/config');
        file_put_contents($this->root . '/project/config/app.yaml', 'a: b');
        $this->archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60, clock: $this->clock);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testPublishVerifiesAndWritesASidecarWithoutLocalPaths(): void
    {
        $target   = new InMemoryRemoteTarget();
        $artifact = $this->archiver->create(new BackupOptions(label: 'Nightly', extraMeta: ['plan' => 'p1']));

        $stored = $this->publisher()->publish($artifact, $target, 'backups/prod/', 'backups/prod/2026/11/a.tar.gz', ['beacon-run' => 'r1'], VerifyMode::FullRehash);

        self::assertSame($artifact->getSizeBytes(), $stored->sizeBytes);
        self::assertSame($artifact->getArchiveSha256(), $stored->metadata['nowo-sha256']);
        self::assertSame('r1', $stored->metadata['beacon-run']);
        self::assertSame('0', $stored->metadata['nowo-encrypted']);
        $sidecarStream = $target->openRead('backups/prod/2026/11/a.tar.gz' . RemoteBackupPublisher::SIDECAR_SUFFIX);
        $raw           = (string) stream_get_contents($sidecarStream);
        $sidecar       = json_decode($raw, true);
        self::assertIsArray($sidecar);
        self::assertSame($artifact->getId(), $sidecar['id']);
        self::assertSame(['plan' => 'p1'], $sidecar['extra_meta']);
        self::assertStringNotContainsString($this->root, $raw, 'no local paths in the remote sidecar');
        self::assertInstanceOf(RemoteBackupUploadedEvent::class, $this->events[0]);
        self::assertSame($stored, $this->events[0]->getObject());
        self::assertSame($artifact, $this->events[0]->getArtifact());
    }

    public function testVerifyModesDetectBadUploads(): void
    {
        $artifact = $this->archiver->create();

        // A target that stores something else than what it was given.
        $corrupting = new class extends ForwardingTarget {
            public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
            {
                $object = parent::upload($stream, $key, $sizeBytes, $metadata);
                if (!str_ends_with($key, '.json')) {
                    $this->inner->overwrite($key, str_repeat('x', $sizeBytes));
                }

                return $object;
            }
        };
        try {
            $this->publisher()->publish($artifact, $corrupting, '', 'a.tar.gz', [], VerifyMode::FullRehash);
            self::fail('Expected RemoteTargetException');
        } catch (RemoteTargetException $e) {
            self::assertStringContainsString('stored bytes differ', $e->getMessage());
        }
        self::assertSame([], iterator_to_array($corrupting->list('')), 'a bad upload is deleted and gets no sidecar');

        // Size only accepts it, size + metadata catches a wrong sha in the metadata.
        $this->publisher()->publish($artifact, $corrupting, '', 'b.tar.gz', [], VerifyMode::Size);
        $lying = new class extends ForwardingTarget {
            public function head(string $key): ?RemoteObject
            {
                $object = parent::head($key);

                return $object === null ? null : new RemoteObject($object->key, $object->sizeBytes, $object->lastModified, ['nowo-sha256' => 'nope']);
            }
        };
        $this->expectException(RemoteTargetException::class);
        $this->publisher()->publish($artifact, $lying, '', 'c.tar.gz');
    }

    public function testUploadFailuresAreRedactedAndCleanedUp(): void
    {
        $artifact = $this->archiver->create();
        $failing  = new class extends ForwardingTarget {
            public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
            {
                throw new RuntimeException('PUT https://bucket.s3/x?X-Amz-Signature=CANARYSIG&X-Amz-Credential=AKIACANARY failed');
            }
        };

        try {
            $this->publisher()->publish($artifact, $failing, '', 'a.tar.gz');
            self::fail('Expected RemoteTargetException');
        } catch (RemoteTargetException $e) {
            self::assertStringNotContainsString('CANARY', $e->getMessage());
            self::assertNull($e->getPrevious());
        }

        $missing = new class extends ForwardingTarget {
            public function head(string $key): ?RemoteObject
            {
                return null;
            }
        };
        try {
            $this->publisher()->publish($artifact, $missing, '', 'a.tar.gz');
            self::fail('Expected RemoteTargetException');
        } catch (RemoteTargetException $e) {
            self::assertStringContainsString('size mismatch or object missing', $e->getMessage());
        }

        $gone = new BackupArtifact('x', 'x.tar.gz', $this->root . '/missing.tar.gz', new DateTimeImmutable(), 1, 'x');
        $this->expectException(RemoteTargetException::class);
        $this->publisher()->publish($gone, new InMemoryRemoteTarget(), '', 'x.tar.gz');
    }

    public function testKeyAndMetadataValidation(): void
    {
        $artifact = $this->archiver->create();
        $target   = new InMemoryRemoteTarget();

        foreach ([
            ['p/', 'other/a.tar.gz', []],
            ['p', 'p/a.tar.gz', []],
            ['p/', 'p/', []],
            ['p/', 'p/../a.tar.gz', []],
            ['/p/', '/p/a.tar.gz', []],
            ['p/', "p/a\n.tar.gz", []],
            ['p/', 'p/a.tar.gz.backup.json', []],
            ['p/', 'p/a.tar.gz', ['nowo-sha256' => 'x']],
            ['p/', 'p/a.tar.gz', ['Bad Key' => 'x']],
            ['p/', 'p/a.tar.gz', ['ok' => "\x01"]],
        ] as [$prefix, $key, $meta]) {
            try {
                $this->publisher()->publish($artifact, $target, $prefix, $key, $meta);
                self::fail('Expected refusal of ' . $key);
            } catch (RemoteTargetException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], iterator_to_array($target->list('')));
    }

    public function testPruneKeepsTheNewestAndOnlyTouchesSidecaredArchives(): void
    {
        $target    = new InMemoryRemoteTarget(false);
        $publisher = $this->publisher();
        $ids       = [];
        for ($i = 0; $i < 4; ++$i) {
            $artifact = $this->archiver->create();
            $publisher->publish($artifact, $target, 'b/', 'b/' . $artifact->getId() . '.tar.gz');
            $ids[] = $artifact->getId();
            $this->clock->sleep(86400);
        }
        // Foreign objects and a sidecar-less archive under the prefix, and one outside it.
        $this->put($target, 'b/foreign.bin');
        $this->put($target, 'b/orphan.tar.gz');
        $this->put($target, 'c/x.tar.gz');
        $this->put($target, 'b/broken.tar.gz');
        $this->put($target, 'b/broken.tar.gz.backup.json', '{not json');

        $report = $publisher->prune($target, 'b/', new RetentionPolicy(keepLast: 2), $ids[3]);

        self::assertSame(['b/' . $ids[0] . '.tar.gz', 'b/' . $ids[1] . '.tar.gz'], $report->deletedKeys);
        self::assertSame([], $report->errors);
        $left = array_map(static fn (RemoteObject $o) => $o->key, iterator_to_array($target->list(''), false));
        self::assertContains('b/foreign.bin', $left);
        self::assertContains('b/orphan.tar.gz', $left);
        self::assertContains('c/x.tar.gz', $left);
        self::assertNotContains('b/' . $ids[0] . '.tar.gz.backup.json', $left);
        self::assertInstanceOf(RemoteBackupPrunedEvent::class, $this->events[count($this->events) - 1]);

        // A failed run (id not uploaded) prunes nothing; a filter limits the plan.
        self::assertSame([], $publisher->prune($target, 'b/', new RetentionPolicy(keepLast: 0), 'never-uploaded')->deletedKeys);
        self::assertSame([], $publisher->prune($target, 'b/', new RetentionPolicy(keepLast: 0), $ids[3], static fn (array $s) => false)->deletedKeys);
        self::assertSame(['b/' . $ids[2] . '.tar.gz'], $publisher->prune($target, 'b/', new RetentionPolicy(keepLast: 0), $ids[3])->deletedKeys);

        $orphans = array_map(static fn (RemoteObject $o) => $o->key, $publisher->orphans($target, 'b/', 0));
        self::assertSame(['b/foreign.bin', 'b/orphan.tar.gz'], $orphans);
        self::assertSame([], $publisher->orphans($target, 'b/', 999_999_999));
    }

    public function testPruneReportsDeleteErrorsAndListFailuresAreWrapped(): void
    {
        $inner     = new InMemoryRemoteTarget();
        $publisher = $this->publisher();
        $ids       = [];
        for ($i = 0; $i < 2; ++$i) {
            $artifact = $this->archiver->create();
            $publisher->publish($artifact, $inner, '', $artifact->getId() . '.tar.gz');
            $ids[] = $artifact->getId();
            $this->clock->sleep(60);
        }
        $undeletable = new class($inner) extends ForwardingTarget {
            public function delete(string $key): void
            {
                throw new RuntimeException('denied');
            }
        };

        $report = $publisher->prune($undeletable, '', new RetentionPolicy(keepLast: 0), $ids[1]);
        self::assertSame([$ids[0] . '.tar.gz' => RuntimeException::class], $report->errors);

        $unlistable = new class extends ForwardingTarget {
            public function list(string $prefix): iterable
            {
                throw new RuntimeException('token=CANARYTOKEN');
            }
        };
        try {
            $publisher->orphans($unlistable, '');
            self::fail('Expected RemoteTargetException');
        } catch (RemoteTargetException $e) {
            self::assertStringNotContainsString('CANARYTOKEN', $e->getMessage());
        }
    }

    public function testInMemoryTarget(): void
    {
        $target = new InMemoryRemoteTarget();
        self::assertNull($target->head('x'));
        $target->delete('x');
        $target->overwrite('x', 'y');
        $this->expectException(RemoteTargetException::class);
        $target->openRead('x');
    }

    private function put(BackupRemoteTargetInterface $target, string $key, string $data = 'x'): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $data);
        rewind($stream);
        $target->upload($stream, $key, strlen($data), []);
    }

    private function publisher(): RemoteBackupPublisher
    {
        $events = &$this->events;

        return new RemoteBackupPublisher(new class($events) implements EventDispatcherInterface {
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
        }, $this->clock);
    }
}
