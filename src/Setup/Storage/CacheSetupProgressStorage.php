<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

use function is_array;

/**
 * Holds setup wizard progress in a PSR-6 cache pool (typically Redis via cache.app).
 *
 * Used as a cold-start bridge until Doctrine can persist the singleton progress row.
 */
final class CacheSetupProgressStorage implements SetupProgressStorageInterface
{
    public const DEFAULT_CACHE_KEY = 'nowo_site_backup.setup_progress';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $cacheKey = self::DEFAULT_CACHE_KEY,
        private readonly int $ttlSeconds = 86400,
    ) {
    }

    public function load(): SetupProgress
    {
        try {
            $item = $this->cache->getItem($this->cacheKey);
            if (!$item->isHit()) {
                return new SetupProgress();
            }

            $data = $item->get();
            if (!is_array($data)) {
                return new SetupProgress();
            }

            /* @var array<string, mixed> $data */
            return SetupProgress::fromArray($data);
        } catch (Throwable) {
            return new SetupProgress();
        }
    }

    public function save(SetupProgress $progress): void
    {
        try {
            $idleEmpty = $progress->getPhase() === SetupProgress::PHASE_IDLE
                && $progress->getCompletedStepIds() === []
                && $progress->getCurrentStepId() === null;

            if ($idleEmpty) {
                $this->cache->deleteItem($this->cacheKey);

                return;
            }

            $item = $this->cache->getItem($this->cacheKey);
            $item->set($progress->toArray());
            $item->expiresAfter($this->ttlSeconds);
            $this->cache->save($item);
        } catch (Throwable) {
            // Cache cold / unreachable — Doctrine path may still succeed later.
        }
    }
}
