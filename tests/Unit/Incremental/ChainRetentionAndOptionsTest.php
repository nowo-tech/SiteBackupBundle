<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\IncrementalOptions;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Retention\RetentionItem;
use Nowo\SiteBackupBundle\Retention\RetentionPlanner;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function in_array;
use function mt_rand;
use function mt_srand;
use function sprintf;

final class ChainRetentionAndOptionsTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-14 12:00:00 UTC');
    }

    /**
     * Two weekly chains (full + 6 daily incrementals) and the current one (full + 2): which ids each policy deletes.
     *
     * @return iterable<string, array{RetentionPolicy, list<string>}>
     */
    public static function matrix(): iterable
    {
        $chain = static fn (string $c, int $n): array => [$c . '-full', ...array_map(static fn (int $i): string => $c . '-i' . $i, $n > 0 ? range(1, $n) : [])];
        $old   = [...$chain('a', 6), ...$chain('b', 6)];

        yield 'keepLast 1 keeps the current chain whole' => [new RetentionPolicy(keepLast: 1), $old];
        yield 'keepLast 3 keeps the current chain only' => [new RetentionPolicy(keepLast: 3), $old];
        yield 'keepLast 4 reaches into chain b: b is kept whole' => [new RetentionPolicy(keepLast: 4), $chain('a', 6)];
        yield 'keepLast 0' => [new RetentionPolicy(keepLast: 0), $old];
        yield 'maxAgeDays 8 keeps chain b (its head is young)' => [new RetentionPolicy(maxAgeDays: 8), $chain('a', 6)];
        yield 'empty policy' => [new RetentionPolicy(), []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('matrix')]
    public function testChainsArePrunedWhole(RetentionPolicy $policy, array $expected): void
    {
        $deleted = RetentionPlanner::plan($this->items(), $policy, 'c-i2', $this->now);

        self::assertEqualsCanonicalizing($expected, $deleted);
        $this->assertNoKeptIncrementalLosesItsParent($deleted);
    }

    public function testAnUnsucceededMemberKeepsItsChainAndAncestorsAreKeptAcrossChainIds(): void
    {
        $items   = $this->items();
        $items[] = new RetentionItem('a-ghost', $this->now->modify('-20 days'), false, 'a');
        self::assertNotContains('a-full', RetentionPlanner::plan($items, new RetentionPolicy(keepLast: 1), 'c-i2', $this->now));

        // An incremental that names a parent outside its chain id still keeps that parent.
        $items   = $this->items();
        $items[] = new RetentionItem('stray', $this->now->modify('-1 hour'), true, 'z', 'a-i3');
        $deleted = RetentionPlanner::plan($items, new RetentionPolicy(keepLast: 4), 'c-i2', $this->now);
        foreach (['a-full', 'a-i1', 'a-i2', 'a-i3'] as $kept) {
            self::assertNotContains($kept, $deleted);
        }
    }

    public function testRandomisedInvariant(): void
    {
        mt_srand(36);
        for ($run = 0; $run < 200; ++$run) {
            $items = [];
            $t     = $this->now->modify('-60 days');
            for ($c = 0; $c < mt_rand(1, 5); ++$c) {
                $parent  = 'c' . $c . '-full';
                $items[] = new RetentionItem($parent, $t = $t->modify('+1 hour'), true, 'c' . $c);
                for ($i = 1; $i <= mt_rand(0, 6); ++$i) {
                    $id      = sprintf('c%d-i%d', $c, $i);
                    $items[] = new RetentionItem($id, $t = $t->modify('+' . mt_rand(1, 48) . ' hours'), mt_rand(0, 9) > 0, 'c' . $c, $parent);
                    $parent  = $id;
                }
            }
            $justCreated = $items[mt_rand(0, count($items) - 1)];
            if (!$justCreated->succeeded) {
                continue;
            }
            $policy  = new RetentionPolicy(keepLast: mt_rand(0, 4), maxAgeDays: mt_rand(0, 1) === 1 ? mt_rand(1, 30) : null);
            $deleted = RetentionPlanner::plan($items, $policy, $justCreated->id, $this->now);

            self::assertNotContains($justCreated->id, $deleted);
            foreach ($items as $item) {
                if (!in_array($item->id, $deleted, true) && $item->parentId !== null) {
                    self::assertNotContains($item->parentId, $deleted, 'a kept incremental lost its parent');
                }
                if (in_array($item->id, $deleted, true)) {
                    foreach ($items as $other) {
                        if ($other->chainId === $item->chainId) {
                            self::assertContains($other->id, $deleted, 'a chain was cut');
                        }
                    }
                }
            }
        }
    }

    public function testRetentionItemFromMeta(): void
    {
        $item = RetentionItem::fromMeta('x', $this->now, ['chain_id' => 'c', 'parent_id' => '']);
        self::assertSame('c', $item->chainId);
        self::assertNull($item->parentId);
        self::assertNull(RetentionItem::fromArtifact(ChainFixtures::artifact('p', '2026-01-01', []))->chainId);
    }

    public function testIncrementalOptionsFromAFullBackup(): void
    {
        $full    = ChainFixtures::full();
        $options = IncrementalOptions::fromParentMeta('full', $full->getMeta(), new BackupOptions(format: ArchiveFormat::TarGz, label: 'nightly'));

        self::assertTrue($options->isIncremental());
        self::assertTrue($options->isChainMember());
        self::assertSame('full', $options->chainId);
        self::assertSame('full', $options->parentId);
        self::assertSame(['config'], $options->includePaths);
        self::assertSame(ArchiveFormat::TarGz, $options->format);
        self::assertSame('nightly', $options->label);
        self::assertTrue($options->binlogFrom?->equals(new BinlogCoordinates('binlog.000002', 1480, ChainFixtures::UUID)));
        self::assertSame($full->getMeta()['file_manifest_sha256'], $options->baseManifest?->sha256());
        self::assertSame('incremental', $options->toLoggableArray()['type']);

        $dbOnly = IncrementalOptions::fromParentMeta('full', ['file_manifest' => null, 'file_manifest_sha256' => null] + $full->getMeta());
        self::assertFalse($dbOnly->includeFiles);
        $filesOnly = IncrementalOptions::fromParentMeta('full', ['binlog_file' => null, 'database_dump' => null] + $full->getMeta());
        self::assertFalse($filesOnly->includeDatabase);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unusableParents(): iterable
    {
        yield 'not a chain' => [['type' => null], 'not part of a chain'];
        yield 'dump without coordinates' => [['binlog_file' => null], 'without binlog coordinates'];
        yield 'broken coordinates' => [['binlog_position' => 'x'], 'unreadable'];
        yield 'broken manifest' => [['file_manifest' => ['scope' => 'x']], 'file manifest of backup full is unreadable'];
        yield 'manifest hash' => [['file_manifest_sha256' => 'x'], 'does not match'];
        yield 'nothing to continue' => [['binlog_file' => null, 'database_dump' => null, 'file_manifest' => null, 'file_manifest_sha256' => null, 'binlog_position' => null], 'neither binlog coordinates nor'];
        yield 'manifest only in the archive' => [['file_manifest' => null], 'use incrementalOptionsFromArchive()'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('unusableParents')]
    public function testUnusableParents(array $override, string $message): void
    {
        $this->expectException(InvalidBackupOptionsException::class);
        $this->expectExceptionMessage($message);
        IncrementalOptions::fromParentMeta('full', $override + ChainFixtures::full()->getMeta());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidChainOptions(): iterable
    {
        $from = new BinlogCoordinates('binlog.000002', 4, ChainFixtures::UUID);
        $base = ChainFixtures::manifest(0);
        yield 'coordinates without database' => [['recordBinlogCoordinates' => true, 'includeDatabase' => false], 'needs includeDatabase'];
        yield 'coordinates with table rules' => [['recordBinlogCoordinates' => true, 'tableRules' => ['t' => TableRule::Skip]], 'table rules'];
        yield 'manifest without files' => [['recordFileManifest' => true, 'includeFiles' => false], 'needs includeFiles'];
        yield 'parent without chain' => [['parentId' => 'p'], 'go together'];
        yield 'bad ids' => [['parentId' => '../p', 'chainId' => 'c'], 'backup ids'];
        yield 'delta without parent' => [['baseManifest' => $base], 'set parentId'];
        yield 'record on incremental' => [['parentId' => 'p', 'chainId' => 'c', 'baseManifest' => $base, 'binlogFrom' => $from, 'recordFileManifest' => true], 'leave recordBinlogCoordinates'];
        yield 'database without binlogFrom' => [['parentId' => 'p', 'chainId' => 'c', 'baseManifest' => $base], 'exactly when binlogFrom'];
        yield 'files without base' => [['parentId' => 'p', 'chainId' => 'c', 'binlogFrom' => $from], 'exactly when baseManifest'];
        yield 'sql.gz' => [['parentId' => 'p', 'chainId' => 'c', 'binlogFrom' => $from, 'includeFiles' => false, 'format' => ArchiveFormat::SqlGz], 'tar.gz or a zip'];
        yield 'table rules on incremental' => [['parentId' => 'p', 'chainId' => 'c', 'binlogFrom' => $from, 'includeFiles' => false, 'tableRules' => ['t' => TableRule::Skip]], 'captures every table'];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('invalidChainOptions')]
    public function testInvalidChainOptions(array $args, string $message): void
    {
        $this->expectException(InvalidBackupOptionsException::class);
        $this->expectExceptionMessage($message);
        new BackupOptions(...$args);
    }

    public function testChainOptionsSurviveCopies(): void
    {
        $options = new BackupOptions(recordBinlogCoordinates: true, recordFileManifest: true);
        $copy    = $options->withLabel('x')->withFormat(ArchiveFormat::Zip);

        self::assertTrue($copy->recordBinlogCoordinates);
        self::assertTrue($copy->recordFileManifest);
        self::assertSame('full', $copy->toLoggableArray()['type']);
        self::assertArrayNotHasKey('type', (new BackupOptions())->toLoggableArray());
    }

    /**
     * @return list<RetentionItem>
     */
    private function items(): array
    {
        $items = [];
        foreach (['a' => -20, 'b' => -13, 'c' => -6] as $chain => $days) {
            $start   = $this->now->modify($days . ' days');
            $items[] = new RetentionItem($chain . '-full', $start, true, $chain);
            $parent  = $chain . '-full';
            $count   = $chain === 'c' ? 2 : 6;
            for ($i = 1; $i <= $count; ++$i) {
                $items[] = new RetentionItem($chain . '-i' . $i, $start->modify('+' . $i . ' days'), true, $chain, $parent);
                $parent  = $chain . '-i' . $i;
            }
        }

        return $items;
    }

    /**
     * @param list<string> $deleted
     */
    private function assertNoKeptIncrementalLosesItsParent(array $deleted): void
    {
        foreach ($this->items() as $item) {
            if (!in_array($item->id, $deleted, true) && $item->parentId !== null) {
                self::assertNotContains($item->parentId, $deleted);
            }
        }
    }
}
