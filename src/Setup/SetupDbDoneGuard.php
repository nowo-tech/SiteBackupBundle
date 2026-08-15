<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Throwable;

/**
 * Closes the wizard when a durable store says setup completed and detectors agree.
 *
 * Re-creates ephemeral file markers and persisted progress so SiteBackup internals match
 * the host durable row after a container recreate.
 */
final readonly class SetupDbDoneGuard
{
    public function __construct(
        private DurableSetupDoneStoreInterface $durableDoneStore,
        private SetupNeedEvaluator $needEvaluator,
        private SetupMarkerManager $markers,
        private SetupProgressStorageInterface $progressStorage,
    ) {
    }

    /**
     * True when the durable store marks complete and SiteBackup detectors do not still require setup.
     */
    public function shouldCloseWizard(): bool
    {
        try {
            if (!$this->durableDoneStore->isDone()) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        if ($this->needEvaluator->isSetupRequired()) {
            return false;
        }

        $this->healSideEffects();

        return true;
    }

    /**
     * Re-create ephemeral markers and persist completed progress after var/ was wiped.
     */
    public function healSideEffects(): void
    {
        if (!$this->markers->isDone()) {
            $this->markers->markDone();
        }

        try {
            $progress = $this->progressStorage->load();
        } catch (Throwable) {
            return;
        }

        if ($progress->getPhase() === SetupProgress::PHASE_COMPLETED) {
            return;
        }

        $now = new DateTimeImmutable();
        $this->progressStorage->save($progress->with(
            phase: SetupProgress::PHASE_COMPLETED,
            clearCurrentStepId: true,
            percent: 100.0,
            message: 'Setup completed (restored from durable setup-done store).',
            clearError: true,
            updatedAt: $now,
            startedAt: $progress->getStartedAt() ?? $now,
            completedAt: $progress->getCompletedAt() ?? $now,
        ));
    }
}
