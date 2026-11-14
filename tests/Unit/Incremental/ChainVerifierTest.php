<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Incremental\BackupChain;
use Nowo\SiteBackupBundle\Incremental\ChainLink;
use Nowo\SiteBackupBundle\Incremental\ChainVerifier;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_values;
use function implode;

final class ChainVerifierTest extends TestCase
{
    public function testAValidChain(): void
    {
        $chain = $this->chain();

        self::assertSame([], ChainVerifier::verify($chain->links));
        ChainVerifier::assertValid($chain->links);
        self::assertSame(['full', 'i1', 'i2'], $chain->ids());
        self::assertSame('full', $chain->root()->getId());
        self::assertSame('i2', $chain->head()->getId());
        self::assertTrue($chain->links[1]->isChainMember());
        self::assertSame(['binlog.000002', 'binlog.000003'], $chain->links[1]->binlogFileNames());
    }

    public function testEmptyAndPlainBackups(): void
    {
        self::assertSame(['The chain is empty.'], ChainVerifier::verify([]));
        $plain = ChainLink::fromArtifact(ChainFixtures::artifact('plain', '2026-11-01', ['database_dump' => 'database/dump.sql']));
        self::assertFalse($plain->isChainMember());
        self::assertNotSame([], ChainVerifier::verify([$plain]));
        // A lone full backup without coordinates is a valid chain of one (nothing to replay).
        self::assertSame([], ChainVerifier::verify([ChainLink::fromArtifact(ChainFixtures::full('f', ['binlog_file' => null]))]));

        $this->expectException(InvalidArgumentException::class);
        new BackupChain([]);
    }

    /**
     * @return iterable<string, array{callable(BackupArtifact, BackupArtifact): list<BackupArtifact>, string}>
     */
    public static function brokenChains(): iterable
    {
        yield 'root is incremental' => [static fn (BackupArtifact $f, BackupArtifact $i1): array => [$i1], 'is not the full backup'];
        yield 'missing parent' => [static fn (BackupArtifact $f, BackupArtifact $i1): array => [$f, ChainFixtures::incremental('i2', $i1, 2)], 'parent of backup i2 is missing'];
        yield 'other chain' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, [], 'other')], 'belongs to another chain'];
        yield 'older than parent' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['_' => 1])], ''];
        yield 'two fulls' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::full('f2', ['parent_id' => 'full'])], 'is not an incremental'];
        yield 'empty incremental' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_from' => null, 'base_manifest_sha256' => null, 'file_manifest' => null])], 'neither binary logs nor a file delta'];
        yield 'other server' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['server_uuid' => ChainFixtures::OTHER])], 'another MySQL server'];
        yield 'gap' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_from' => ['binlog_file' => 'binlog.000002', 'binlog_position' => 2000, 'server_uuid' => ChainFixtures::UUID]])], 'gap or overlap'];
        yield 'other database' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_database' => 'x'])], 'another database'];
        yield 'no binlog in db chain' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_from' => null])], 'no binary logs although'];
        yield 'files missing while coordinates moved' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_files' => []])], 'lists no binary log'];
        yield 'wrong first file' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_files' => [['name' => 'binlog.000005', 'sha256' => 'x', 'size' => 1], ['name' => 'binlog.000003', 'sha256' => 'x', 'size' => 1]]])], 'do not start at'];
        yield 'not contiguous' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_files' => [['name' => 'binlog.000002', 'sha256' => 'x', 'size' => 1], ['name' => 'other.000003', 'sha256' => 'x', 'size' => 1]]])], 'not contiguous'];
        yield 'wrong last file' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['binlog_files' => [['name' => 'binlog.000002', 'sha256' => 'x', 'size' => 1]]])], 'do not end at'];
        yield 'overlap' => [static fn (BackupArtifact $f, BackupArtifact $i1): array => [$f, $i1, ChainFixtures::incremental('i2', $i1, 2, ['binlog_files' => [['name' => 'binlog.000003', 'sha256' => 'x', 'size' => 901], ['name' => 'binlog.000004', 'sha256' => 'x', 'size' => 902]]])], 'overlap those of i1'];
        yield 'dump without coordinates' => [static fn (BackupArtifact $f): array => [ChainFixtures::full('full', ['binlog_file' => null]), ChainFixtures::incremental('i1', $f, 1)], 'without binlog coordinates'];
        yield 'file delta on the wrong base' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['base_manifest_sha256' => 'x'])], 'is not based on the files'];
        yield 'no manifest in delta' => [static fn (BackupArtifact $f): array => [$f, ChainFixtures::incremental('i1', $f, 1, ['file_manifest' => null])], 'has no file manifest'];
        yield 'root manifest hash missing' => [static fn (BackupArtifact $f, BackupArtifact $i1): array => [ChainFixtures::full('full', ['file_manifest_sha256' => null]), $i1], 'no file_manifest_sha256'];
        yield 'delta without root files' => [static fn (BackupArtifact $f, BackupArtifact $i1): array => [ChainFixtures::full('full', ['file_manifest' => null]), $i1], 'has a file delta but'];
        yield 'binlog without root database' => [static fn (BackupArtifact $f, BackupArtifact $i1): array => [ChainFixtures::full('full', ['database_dump' => null]), $i1], 'has a database part but'];
    }

    /**
     * @param callable(BackupArtifact, BackupArtifact): list<BackupArtifact> $build
     */
    #[DataProvider('brokenChains')]
    public function testBrokenChains(callable $build, string $expected): void
    {
        $full = ChainFixtures::full();
        $i1   = ChainFixtures::incremental('i1', $full, 1);
        $list = array_values($build($full, $i1));
        if ($expected === '') {
            // Creation time going back.
            $list[1]  = ChainFixtures::artifact('i1', '2025-01-01 UTC', $list[1]->getMeta());
            $expected = 'older than its parent';
        }

        $problems = ChainVerifier::verify((new BackupChain(array_values($list)))->links);
        self::assertStringContainsString($expected, implode(' | ', $problems));

        $this->expectException(ChainBrokenException::class);
        ChainVerifier::assertValid((new BackupChain(array_values($list)))->links);
    }

    public function testEmptyCaptureIsValidWhenCoordinatesDidNotMove(): void
    {
        $full  = ChainFixtures::full();
        $empty = ChainFixtures::incremental('i1', $full, 1, ['binlog_files' => [], 'binlog_file' => 'binlog.000002', 'binlog_position' => 1480]);

        self::assertSame([], ChainVerifier::verify((new BackupChain([$full, $empty]))->links));
    }

    public function testImportedLinksUseTheOriginalCreationTime(): void
    {
        $link = ChainLink::fromArtifact(ChainFixtures::artifact('x', '2026-11-10 UTC', ['imported_from' => ['created_at' => '2026-11-01T00:00:00+00:00'], 'first_event_at' => 'garbage']));
        self::assertSame('2026-11-01', $link->createdAt->format('Y-m-d'));
        self::assertNull($link->firstEventAt);
    }

    private function chain(): BackupChain
    {
        $full = ChainFixtures::full();
        $i1   = ChainFixtures::incremental('i1', $full, 1);
        $i2   = ChainFixtures::incremental('i2', $i1, 2);

        return new BackupChain([$full, $i1, $i2]);
    }
}
