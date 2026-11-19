<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Nowo\SiteBackupBundle\Lock\LocalLockFallbackPolicyInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Throwable;

/**
 * `setup.lock_fallback_local` (1.37+, audit UT-1): when the shared lock store fails, the setup lock may use the local
 * lock file only on a genuinely cold instance (no setup.done, not re-opened, the durable done store not done): there
 * the store is often the database being set up, and only one server runs the first install. A configured or
 * re-opened instance answers 503 instead, so servers keep excluding each other.
 *
 * Modes: `cold_only` (default), `always` (pre-audit behaviour), `never`. Reads markers on each call (worker mode).
 */
final readonly class SetupLockFallbackPolicy implements LocalLockFallbackPolicyInterface
{
    public const COLD_ONLY = 'cold_only';

    public const ALWAYS = 'always';

    public const NEVER = 'never';

    public function __construct(
        private SetupMarkerManager $markers,
        private ?DurableSetupDoneStoreInterface $durableDoneStore = null,
        private string $mode = self::COLD_ONLY,
    ) {
    }

    public function allowsLocalFallback(): bool
    {
        return match ($this->mode) {
            self::ALWAYS => true,
            self::NEVER  => false,
            default      => $this->isCold(),
        };
    }

    private function isCold(): bool
    {
        if ($this->markers->isDone() || $this->markers->isReopened()) {
            return false;
        }

        try {
            return !($this->durableDoneStore?->isDone() ?? false);
        } catch (Throwable) {
            // The durable store is usually the database that is not reachable yet; the file markers decided above.
            return true;
        }
    }
}
