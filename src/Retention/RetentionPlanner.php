<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Retention;

use DateTimeImmutable;
use DateTimeZone;

use function array_keys;
use function array_pop;
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
 * 6. an empty policy deletes nothing;
 * 7. (1.36+) chains are pruned whole: when any member of a chain (same `chainId`) is kept — or is not succeeded, or
 *    is the parent of a kept item — every member of that chain and every ancestor (`parentId`) is kept. A full backup
 *    that a kept incremental depends on is never returned.
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

        $keep = self::closeOverChains($items, $keep);

        $delete = [];
        foreach ($succeeded as $item) {
            if (!isset($keep[$item->id])) {
                $delete[] = $item->id;
            }
        }

        return array_values(array_reverse($delete));
    }

    /**
     * Grows the keep set until it is closed: a kept item keeps its whole chain and its ancestors; a chain with a member
     * that is not succeeded (unknown state, e.g. an untrusted remote sidecar) is kept whole too.
     *
     * @param list<RetentionItem> $items
     * @param array<string, true> $keep
     *
     * @return array<string, true>
     */
    private static function closeOverChains(array $items, array $keep): array
    {
        $byId     = [];
        $byChain  = [];
        $chainsOf = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
            if ($item->chainId !== null) {
                $byChain[$item->chainId][] = $item->id;
                $chainsOf[$item->id]       = $item->chainId;
            }
            if (!$item->succeeded) {
                $keep[$item->id] = true;
            }
        }

        $queue = array_keys($keep);
        while ($queue !== []) {
            $id      = array_pop($queue);
            $related = [];
            if (isset($chainsOf[$id])) {
                $related = $byChain[$chainsOf[$id]];
            }
            $parent = isset($byId[$id]) ? $byId[$id]->parentId : null;
            if ($parent !== null) {
                $related[] = $parent;
            }
            foreach ($related as $other) {
                if (!isset($keep[$other])) {
                    $keep[$other] = true;
                    $queue[]      = $other;
                }
            }
        }

        return $keep;
    }
}
