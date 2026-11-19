<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Lock;

use Error;
use RuntimeException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * A lock store whose operations fail on demand (Redis / database outage).
 */
final class FailingStore implements PersistingStoreInterface
{
    public function __construct(
        private readonly bool $save = false,
        private readonly bool $refresh = false,
        private readonly bool $conflictOnRefresh = false,
        private readonly bool $delete = false,
        private readonly bool $error = false,
    ) {
    }

    private int $refreshes = 0;

    public function save(Key $key): void
    {
        if ($this->error) {
            // Not an \Exception: Lock::acquire() does not wrap it (an unexpected error, audit UT3-5).
            throw new Error('store bug');
        }
        if ($this->save) {
            throw new RuntimeException('store down');
        }
        $key->reduceLifetime(60);
    }

    public function delete(Key $key): void
    {
        if ($this->delete) {
            throw new RuntimeException('store down');
        }
    }

    public function exists(Key $key): bool
    {
        return false;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // The first call is the acquire itself (Lock::acquire() sets the TTL); later calls are heartbeats.
        if (++$this->refreshes === 1) {
            $key->reduceLifetime($ttl);

            return;
        }
        if ($this->conflictOnRefresh) {
            throw new LockConflictedException();
        }
        if ($this->refresh) {
            throw new RuntimeException('store down');
        }
        $key->reduceLifetime($ttl);
    }
}
