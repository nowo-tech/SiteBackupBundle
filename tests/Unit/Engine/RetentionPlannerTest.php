<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Retention\RetentionItem;
use Nowo\SiteBackupBundle\Retention\RetentionPlanner;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function in_array;
use function mt_rand;
use function mt_srand;
use function range;
use function sprintf;

final class RetentionPlannerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-04 12:00:00 UTC');
    }

    public function testEmptyPolicyDeletesNothing(): void
    {
        self::assertSame([], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(), 'd0', $this->now));
        self::assertTrue((new RetentionPolicy())->isEmpty());
    }

    public function testKeepLast(): void
    {
        self::assertSame(['d4', 'd3', 'd2'], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(keepLast: 2), 'd0', $this->now));
    }

    public function testKeepLastZeroKeepsOnlyTheNewOne(): void
    {
        self::assertSame(['d4', 'd3', 'd2', 'd1'], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(keepLast: 0), 'd0', $this->now));
    }

    public function testMaxAgeDays(): void
    {
        self::assertSame(['d4', 'd3'], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(maxAgeDays: 3), 'd0', $this->now));
    }

    public function testRulesAreAUnion(): void
    {
        self::assertSame(['d4'], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(keepLast: 1, maxAgeDays: 4), 'd0', $this->now));
    }

    public function testFailedRunPrunesNothing(): void
    {
        self::assertSame([], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(keepLast: 0), 'missing', $this->now));
    }

    public function testUnsucceededItemsAreIgnored(): void
    {
        $items   = $this->daily(3);
        $items[] = new RetentionItem('failed', $this->now->modify('-10 days'), false);

        self::assertSame(['d2', 'd1'], RetentionPlanner::plan($items, new RetentionPolicy(keepLast: 0), 'd0', $this->now));
    }

    public function testNewestSucceededIsNeverDeletedEvenWhenNotJustCreated(): void
    {
        // The just-created item is older than another one (clock skew): both stay.
        $items = [new RetentionItem('old-new', $this->now->modify('-1 day')), new RetentionItem('newest', $this->now), new RetentionItem('x', $this->now->modify('-5 days'))];

        self::assertSame(['x'], RetentionPlanner::plan($items, new RetentionPolicy(keepLast: 0), 'old-new', $this->now));
    }

    public function testGrandfatherFatherSon(): void
    {
        $items = [];
        for ($i = 0; $i < 70; ++$i) {
            // Two backups a day for 70 days.
            $items[] = new RetentionItem(sprintf('a%02d', $i), $this->now->modify(sprintf('-%d days', $i)));
            $items[] = new RetentionItem(sprintf('b%02d', $i), $this->now->modify(sprintf('-%d days -6 hours', $i)));
        }

        $delete = RetentionPlanner::plan($items, new RetentionPolicy(keepDaily: 3, keepWeekly: 2, keepMonthly: 3, keepLast: 0), 'a00', $this->now);
        $kept   = [];
        foreach ($items as $item) {
            if (!in_array($item->id, $delete, true)) {
                $kept[] = $item->id;
            }
        }

        // 3 days (newest of each) + newest of 2 ISO weeks + newest of 3 months, deduplicated.
        self::assertContains('a00', $kept);
        self::assertContains('a01', $kept);
        self::assertContains('a02', $kept);
        self::assertNotContains('b01', $kept);
        self::assertLessThanOrEqual(8, count($kept));
        self::assertGreaterThanOrEqual(5, count($kept));
    }

    public function testDailyZeroIsIgnored(): void
    {
        self::assertSame(['d4', 'd3', 'd2', 'd1'], RetentionPlanner::plan($this->daily(5), new RetentionPolicy(keepLast: 0, keepDaily: 0), 'd0', $this->now));
    }

    public function testInvariantsOverRandomHistories(): void
    {
        mt_srand(20261104);
        for ($run = 0; $run < 300; ++$run) {
            $items = [];
            $n     = mt_rand(1, 15);
            for ($i = 0; $i < $n; ++$i) {
                $items[] = new RetentionItem('i' . $i, $this->now->modify(sprintf('-%d hours', mt_rand(0, 2000))), mt_rand(0, 4) > 0);
            }
            $created = $items[mt_rand(0, $n - 1)];
            $policy  = new RetentionPolicy(
                keepLast: mt_rand(0, 1) === 1 ? mt_rand(0, 5) : null,
                maxAgeDays: mt_rand(0, 1) === 1 ? mt_rand(0, 60) : null,
                keepDaily: mt_rand(0, 3) === 1 ? mt_rand(0, 4) : null,
            );

            $delete = RetentionPlanner::plan($items, $policy, $created->id, $this->now);

            if (!$created->succeeded || $policy->isEmpty()) {
                self::assertSame([], $delete);
                continue;
            }
            self::assertNotContains($created->id, $delete);
            $newest = null;
            foreach ($items as $item) {
                if ($item->succeeded && ($newest === null || [$item->createdAt, $item->id] > [$newest->createdAt, $newest->id])) {
                    $newest = $item;
                }
                if (!$item->succeeded) {
                    self::assertNotContains($item->id, $delete);
                }
            }
            self::assertNotNull($newest);
            self::assertNotContains($newest->id, $delete);
        }
    }

    public function testPolicyValidation(): void
    {
        try {
            new RetentionPolicy(keepLast: -1);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('keepLast', $e->getMessage());
        }

        $this->expectException(Exception::class);
        new RetentionPolicy(timezone: 'Mars/Olympus');
    }

    public function testItemFromArtifact(): void
    {
        $artifact = new BackupArtifact('id-1', 'id-1.tar.gz', '/tmp/id-1.tar.gz', $this->now, 1, 'abc');
        $item     = RetentionItem::fromArtifact($artifact);

        self::assertSame('id-1', $item->id);
        self::assertTrue($item->succeeded);
        self::assertEquals($this->now, $item->createdAt);
    }

    /**
     * @return list<RetentionItem> d0 (now) … d{n-1} (n-1 days ago)
     */
    private function daily(int $n): array
    {
        return array_map(fn (int $i) => new RetentionItem('d' . $i, $this->now->modify(sprintf('-%d days', $i))), range(0, $n - 1));
    }
}
