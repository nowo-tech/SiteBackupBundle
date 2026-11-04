<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Lock;

use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

use function fclose;
use function flock;
use function fopen;
use function rtrim;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * One backup / import / delete / restore at a time (1.28+).
 *
 * - With symfony/lock (`lock.factory`): key `backup.lock.key`, TTL `process_timeout` × 4 + 300 s, refreshed by
 *   {@see BackupOperationLockHandle::refresh()} on every heartbeat. Use a shared store (Redis, DBAL) when several app
 *   containers share the database.
 * - Without it: an `flock()` on `<storage_dir>/.operation.lock` (one host only).
 *
 * Non-blocking: a held lock throws {@see BackupAlreadyRunningException}. Nothing is kept on the service between calls.
 */
final class BackupOperationLock
{
    public const DEFAULT_KEY = 'nowo_site_backup.operation';

    public function __construct(
        private readonly string $lockDirectory,
        private readonly ?LockFactory $lockFactory = null,
        private readonly string $key = self::DEFAULT_KEY,
        private readonly float $ttlSeconds = 2700.0,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws BackupAlreadyRunningException when another operation holds the lock
     */
    public function acquire(): BackupOperationLockHandle
    {
        if ($this->lockFactory instanceof LockFactory) {
            $lock = $this->lockFactory->createLock($this->key, $this->ttlSeconds, false);
            if (!$lock->acquire(false)) {
                throw new BackupAlreadyRunningException();
            }

            return new BackupOperationLockHandle(
                refresh: fn () => $lock->refresh($this->ttlSeconds),
                release: static fn () => $lock->release(),
            );
        }

        $this->filesystem->mkdir($this->lockDirectory, 0o700);
        $handle = fopen(rtrim($this->lockDirectory, '/\\') . '/.operation.lock', 'c');
        if ($handle === false) {
            throw new BackupAlreadyRunningException();
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new BackupAlreadyRunningException();
        }

        return new BackupOperationLockHandle(
            refresh: static function (): void {},
            release: static function () use ($handle): void {
                flock($handle, LOCK_UN);
                fclose($handle);
            },
        );
    }

    public function usesSharedStore(): bool
    {
        return $this->lockFactory instanceof LockFactory;
    }
}
