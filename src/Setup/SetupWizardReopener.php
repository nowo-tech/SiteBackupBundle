<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Throwable;

/**
 * Re-opens the setup wizard after it was marked done (v1.15+).
 *
 * Clears the {@code setup.done} file marker, the host durable store ({@see DurableSetupDoneStoreInterface::clearDone()})
 * and resets persisted wizard progress so the wizard can run again. Used by
 * {@see Detector\SetupNeedEvaluator} when {@code setup.reopen_when_detector_requires} is enabled, and
 * available to host code (e.g. catalog-wipe subscribers) instead of copying the logic.
 *
 * Host-owned state outside SiteBackup (e.g. an instance-settings "setup completed" flag that is not
 * behind the durable store) must still be cleared by the host.
 */
final readonly class SetupWizardReopener
{
    public function __construct(
        private SetupMarkerManager $markers,
        private SetupProgressStorageInterface $progressStorage,
        private ?DurableSetupDoneStoreInterface $durableDoneStore = null,
    ) {
    }

    /**
     * @return bool True when at least one completion signal was cleared (and progress was reset)
     */
    public function reopen(): bool
    {
        $cleared = false;

        if ($this->markers->isDone()) {
            $this->markers->clearDone();
            $cleared = true;
        }

        if ($this->durableDoneStore instanceof DurableSetupDoneStoreInterface) {
            try {
                if ($this->durableDoneStore->isDone()) {
                    $this->durableDoneStore->clearDone();
                    $cleared = true;
                }
            } catch (Throwable) {
                // Cold start / missing schema — file marker clear is enough for this request.
            }
        }

        if ($cleared) {
            $this->resetProgress();
        }

        return $cleared;
    }

    /**
     * Persists a fresh (idle) progress record.
     */
    public function resetProgress(): void
    {
        try {
            $this->progressStorage->save(new SetupProgress());
        } catch (Throwable) {
            // Progress backend unavailable — markers are already cleared.
        }
    }
}
