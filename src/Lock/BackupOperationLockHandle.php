<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Lock;

use Closure;

/**
 * A held {@see BackupOperationLock}. Release it in a `finally`; releasing twice is a no-op.
 */
final class BackupOperationLockHandle
{
    private bool $released = false;

    /**
     * @param Closure(): void $refresh
     * @param Closure(): void $release
     */
    public function __construct(
        private readonly Closure $refresh,
        private readonly Closure $release,
    ) {
    }

    public function refresh(): void
    {
        if (!$this->released) {
            ($this->refresh)();
        }
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        // @igor-ignore - Per-call handle (never a shared service).
        $this->released = true;
        ($this->release)();
    }
}
