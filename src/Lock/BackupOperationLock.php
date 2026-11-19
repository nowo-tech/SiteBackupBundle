<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Lock;

use Closure;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\OperationLockUnavailableException;
use Nowo\SiteBackupBundle\Setup\SetupOrchestrator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

use function fclose;
use function flock;
use function fopen;
use function rtrim;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * One backup / import / delete / restore at a time (1.28+). See docs/CONCURRENCY.md.
 *
 * - With symfony/lock (`lock.factory`): key `backup.lock.key`, TTL `process_timeout` × 4 + 300 s, refreshed by
 *   {@see BackupOperationLockHandle::refresh()} on every heartbeat. Use a shared store (Redis, PDO / DBAL) when
 *   several app containers share the database.
 * - Without it: an `flock()` on `<storage_dir>/.operation.lock` (one host only; the kernel frees it when the process
 *   dies).
 *
 * Non-blocking: a held lock throws {@see BackupAlreadyRunningException} (nothing was started). 1.37+: a failing lock
 * store or an unwritable lock file throws {@see OperationLockUnavailableException} instead (not a conflict); a store
 * failure while refreshing is logged and retried on the next heartbeat (the TTL leaves the margin), losing the lock to
 * another holder aborts the operation; a store failure while releasing is logged and the key expires at its TTL.
 * With a {@see LocalLockFallbackPolicyInterface} (the setup wizard lock) the local flock is always taken first and the
 * shared key second (both released); a failing store then keeps only the local flock when the policy allows it at that
 * moment (cold instances, by default). Nothing is kept
 * on the service between calls (worker mode).
 */
final class BackupOperationLock
{
    public const DEFAULT_KEY = 'nowo_site_backup.operation';

    /** 1.37+: the setup wizard lock ({@see SetupOrchestrator}): `backup.lock.key` + `.setup`. */
    public const SETUP_KEY = self::DEFAULT_KEY . '.setup';

    /**
     * @param (Closure(resource): array{0: bool, 1: int})|null $flock @internal test seam for flock(): [locked, wouldBlock]; null = flock()
     */
    public function __construct(
        private readonly string $lockDirectory,
        private readonly ?LockFactory $lockFactory = null,
        private readonly string $key = self::DEFAULT_KEY,
        private readonly float $ttlSeconds = 2700.0,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly string $fileName = '.operation.lock',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $retryAfterSeconds = 300,
        private readonly ?LocalLockFallbackPolicyInterface $localFallback = null,
        private readonly ?Closure $flock = null,
    ) {
    }

    /**
     * @throws BackupAlreadyRunningException when another operation holds the lock (nothing was started)
     * @throws OperationLockUnavailableException when the lock store or the lock file fails (1.37+)
     */
    public function acquire(): BackupOperationLockHandle
    {
        if (!$this->lockFactory instanceof LockFactory) {
            return $this->acquireLocal();
        }
        if (!$this->localFallback instanceof LocalLockFallbackPolicyInterface) {
            return $this->acquireShared($this->lockFactory);
        }

        // 1.37+ (audit UT2-1): with a local fallback the local file is ALWAYS taken first, then the shared key. A
        // caller that fell back (store down) and a caller that reached the store later still exclude each other on
        // this host.
        $local = $this->acquireLocal();
        try {
            $shared = $this->acquireShared($this->lockFactory);
        } catch (OperationLockUnavailableException $e) {
            // 1.37+ (audit UT-1): only while the policy allows it (a cold first install, by default); otherwise a
            // store outage must not let servers stop excluding each other.
            if (!$this->localFallback->allowsLocalFallback()) {
                $local->release();

                throw $e;
            }
            // Cold instance: the shared store (often the database being set up) may not answer yet.
            $this->logger->warning('SiteBackup: the lock store is unavailable; using the local lock file.', ['bundle' => 'site_backup', 'lock' => $this->key]);

            return $local;
        } catch (BackupAlreadyRunningException $e) {
            $local->release();

            throw $e;
        } catch (Throwable $e) {
            // 1.37+ (audit UT3-5): anything else (a store bug, a type error) never leaves the local file held.
            $local->release();

            throw $e;
        }

        return new BackupOperationLockHandle(
            refresh: static function () use ($shared): void {
                $shared->refresh();
            },
            release: static function () use ($shared, $local): void {
                try {
                    $shared->release();
                } finally {
                    $local->release();
                }
            },
        );
    }

    /**
     * @throws BackupAlreadyRunningException|OperationLockUnavailableException
     */
    private function acquireLocal(): BackupOperationLockHandle
    {
        try {
            $this->filesystem->mkdir($this->lockDirectory, 0o700);
        } catch (IOException $e) {
            throw new OperationLockUnavailableException('The operation lock directory cannot be created; nothing was started.', $e);
        }
        $handle = @fopen(rtrim($this->lockDirectory, '/\\') . '/' . $this->fileName, 'c');
        if ($handle === false) {
            throw new OperationLockUnavailableException('The operation lock file cannot be opened; nothing was started.');
        }
        $wouldBlock = 0;
        if ($this->flock instanceof Closure) {
            [$locked, $wouldBlock] = ($this->flock)($handle);
        } else {
            $locked = flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
        }
        if (!$locked) {
            fclose($handle);
            // 1.37+ (audit UT3-1): only "another process holds it" is a conflict (409). ENOLCK, a file system without
            // a lock manager (NFS / CIFS) or EBADF cannot lock at all: 503, nothing was started.
            if ($wouldBlock !== 0) {
                throw new BackupAlreadyRunningException($this->retryAfterSeconds);
            }

            throw new OperationLockUnavailableException('The operation lock file cannot be locked (the file system may not support flock()); nothing was started.');
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

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    private function acquireShared(LockFactory $factory): BackupOperationLockHandle
    {
        $lock = $factory->createLock($this->key, $this->ttlSeconds, false);
        try {
            $acquired = $lock->acquire(false);
        } catch (LockAcquiringException $e) {
            throw new OperationLockUnavailableException(previous: $e);
        }
        if (!$acquired) {
            throw new BackupAlreadyRunningException($this->retryAfterSeconds);
        }

        $ttl    = $this->ttlSeconds;
        $key    = $this->key;
        $logger = $this->logger;

        return new BackupOperationLockHandle(
            refresh: static function () use ($lock, $ttl, $key, $logger): void {
                self::refreshShared($lock, $ttl, $key, $logger);
            },
            release: static function () use ($lock, $key, $logger): void {
                try {
                    $lock->release();
                } catch (LockReleasingException $e) {
                    // The work is done (or already failed): a store hiccup must not turn it into an error nor hide
                    // the original exception. The key expires at its TTL.
                    $logger->warning('SiteBackup: the operation lock could not be released; it expires at its TTL.', ['bundle' => 'site_backup', 'lock' => $key, 'exception' => $e::class]);
                }
            },
        );
    }

    /**
     * Lost to another holder ({@see LockConflictedException}) or expired: rethrown,
     * the operation stops. Store failure: logged, retried on the next heartbeat.
     */
    private static function refreshShared(LockInterface $lock, float $ttl, string $key, LoggerInterface $logger): void
    {
        try {
            $lock->refresh($ttl);
        } catch (LockAcquiringException $e) {
            $logger->warning('SiteBackup: the operation lock could not be refreshed; retrying on the next heartbeat.', ['bundle' => 'site_backup', 'lock' => $key, 'exception' => $e::class]);
        }
    }
}
