<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Lock;

/**
 * Decides, when the shared lock store fails, whether a {@see BackupOperationLock} may fall back to its local lock file
 * (1.37+). A local file only serialises one host, so this must stay false whenever several servers may run.
 */
interface LocalLockFallbackPolicyInterface
{
    public function allowsLocalFallback(): bool;
}
