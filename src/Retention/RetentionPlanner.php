<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Retention;

use DateTimeImmutable;
use DateTimeZone;

use function array_reverse;
use function array_values;
use function count;
use function min;
use function sprintf;
use function usort;

/**
 * Pure retention maths (1.28+): which ids to delete after a successful backup.
 *
 * Invariants (property-tested):
 * 1. `$justCreatedId` is never returned;
 * 2. the newest succeeded item is never returned;
 * 3. items that are not succeeded are neither counted nor returned (the caller decides about failed runs);
 * 4. when `$justCreatedId` is not among the items (the run failed), nothing is returned;
 * 5. `keepLast = 0` keeps exactly `$justCreatedId` (when no other rule keeps more);
 * 6. an empty policy deletes nothing.
 */
final class RetentionPlanner
{
    /**
     * @param list<RetentionItem> $items
     *
     * @return list<string> ids to delete, oldest first
     */
    public static function plan(array $items, RetentionPolicy $policy, string $justCreatedId, DateTimeImmutable $now): array
    {
        if ($policy->isEmpty()) {
            return [];
        }

        $succeeded = [];
        $found     = false;
        foreach ($items as $item) {
            if (!$item->succeeded) {
                continue;
            }
            $succeeded[] = $item;
            $found       = $found || $item->id === $justCreatedId;
        }
        if (!$found) {
            return [];
        }

        // Newest first; ties broken by id so the result is deterministic.
        usort($succeeded, static fn (RetentionItem $a, RetentionItem $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        $keep = [$justCreatedId => true, $succeeded[0]->id => true];

        if ($policy->keepLast !== null) {
            for ($i = 0, $n = min($policy->keepLast, count($succeeded)); $i < $n; ++$i) {
                $keep[$succeeded[$i]->id] = true;
            }
        }

        if ($policy->maxAgeDays !== null) {
            $limit = $now->modify(sprintf('-%d days', $policy->maxAgeDays));
            foreach ($succeeded as $item) {
                if ($item->createdAt > $limit) {
                    $keep[$item->id] = true;
                }
            }
        }

        $zone = new DateTimeZone($policy->timezone);
        foreach ([[$policy->keepDaily, 'Y-m-d'], [$policy->keepWeekly, 'o-W'], [$policy->keepMonthly, 'Y-m']] as [$count, $format]) {
            if ($count === null || $count === 0) {
                continue;
            }
            $buckets = [];
            foreach ($succeeded as $item) {
                $bucket = $item->createdAt->setTimezone($zone)->format($format);
                if (isset($buckets[$bucket])) {
                    continue;
                }
                if (count($buckets) >= $count) {
                    break;
                }
                $buckets[$bucket] = true;
                $keep[$item->id]  = true;
            }
        }

        $delete = [];
        foreach ($succeeded as $item) {
            if (!isset($keep[$item->id])) {
                $delete[] = $item->id;
            }
        }

        return array_values(array_reverse($delete));
    }
}
