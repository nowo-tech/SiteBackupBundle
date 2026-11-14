<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Incremental\IncrementalOptions;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Remote\InMemoryRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function fopen;
use function fwrite;
use function json_decode;
use function json_encode;
use function rewind;
use function stream_get_contents;
use function strlen;
use function touch;

final class RemoteChainPruneTest extends TestCase
{
    private string $root;

    private MockClock $clock;

    private BackupArchiver $archiver;

    protected function setUp(): void
    {
        $this->root  = EngineFixtures::tempDir('sbb-remote-chain-');
        $this->clock = new MockClock(new DateTimeImmutable('-10 days'));
        (new Filesystem())->mkdir($this->root . '/project/config');
        file_put_contents($this->root . '/project/config/app.yaml', 'a: b');
        touch($this->root . '/project/config/app.yaml', $this->clock->now()->getTimestamp() - 100);
        $this->archiver = new BackupArchiver($this->root . '/project', $this->root . '/storage', ['config'], [], null, 60, clock: $this->clock);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testRemotePruneKeepsChainsWholeAndTheNextIncrementalNeedsOnlyTheSidecar(): void
    {
        $target    = new InMemoryRemoteTarget(false);
        $publisher = new RemoteBackupPublisher(clock: $this->clock);

        $a  = $this->publish($publisher, $target, $this->archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true)));
        $a1 = $this->publish($publisher, $target, $this->archiver->create(IncrementalOptions::fromParentMeta($a->getId(), $a->getMeta())));
        $b  = $this->publish($publisher, $target, $this->archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true)));

        // The sidecar's chain block is enough to continue a chain whose local copy is gone.
        $sidecar = $this->sidecar($target, 'p/' . $b->getId() . '.tar.gz');
        self::assertSame($b->getId(), $sidecar['chain']['chain_id']);
        $next = IncrementalOptions::fromParentMeta($b->getId(), $sidecar['chain']);
        self::assertSame($b->getMeta()['file_manifest_sha256'], $next->baseManifest?->sha256());
        $b1 = $this->publish($publisher, $target, $this->archiver->create($next));
        self::assertArrayNotHasKey('chain', $publisher->sidecar($this->archiver->create()));

        // keepLast 1 keeps b1, hence b; a is a chain of its own: pruned whole.
        $report = $publisher->prune($target, 'p/', new RetentionPolicy(keepLast: 1), $b1->getId());
        self::assertEqualsCanonicalizing(['p/' . $a->getId() . '.tar.gz', 'p/' . $a1->getId() . '.tar.gz'], $report->deletedKeys);

        // An untrusted sidecar of a chain keeps that chain (fail safe).
        $c                = $this->publish($publisher, $target, $this->archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true)));
        $c1               = $this->publish($publisher, $target, $this->archiver->create(IncrementalOptions::fromParentMeta($c->getId(), $c->getMeta())));
        $d                = $this->publish($publisher, $target, $this->archiver->create(new BackupOptions(includeDatabase: false, recordFileManifest: true)));
        $tampered         = $this->sidecar($target, 'p/' . $c1->getId() . '.tar.gz');
        $tampered['size'] = 1;
        $this->put($target, 'p/' . $c1->getId() . '.tar.gz.backup.json', (string) json_encode($tampered));

        $report = $publisher->prune($target, 'p/', new RetentionPolicy(keepLast: 1), $d->getId());
        self::assertNotContains('p/' . $c->getId() . '.tar.gz', $report->deletedKeys);
        self::assertContains('p/' . $b->getId() . '.tar.gz', $report->deletedKeys);
    }

    private function publish(RemoteBackupPublisher $publisher, InMemoryRemoteTarget $target, BackupArtifact $artifact): BackupArtifact
    {
        $publisher->publish($artifact, $target, 'p/', 'p/' . $artifact->getId() . '.tar.gz');
        $this->clock->sleep(3600);

        return $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    private function sidecar(InMemoryRemoteTarget $target, string $key): array
    {
        $stream = $target->openRead($key . '.backup.json');
        $data   = json_decode((string) stream_get_contents($stream), true);
        self::assertIsArray($data);

        return $data;
    }

    private function put(InMemoryRemoteTarget $target, string $key, string $data): void
    {
        $target->delete($key);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $data);
        rewind($stream);
        $target->upload($stream, $key, strlen($data), []);
    }
}
