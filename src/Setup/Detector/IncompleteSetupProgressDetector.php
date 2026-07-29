<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Throwable;

use function sprintf;

/**
 * Forces the site gate when setup progress exists but is not completed
 * (resume after crash / wiped var/ when Doctrine storage still has the row).
 */
final class IncompleteSetupProgressDetector implements SetupNeedDetectorInterface
{
    public function __construct(
        private readonly SetupProgressStorageInterface $progressStorage,
        private readonly SetupMarkerManager $markers,
        private readonly bool $enabled = true,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->markers->isDone()) {
            return false;
        }

        try {
            return $this->progressStorage->load()->isIncomplete();
        } catch (Throwable) {
            return false;
        }
    }

    public function getReason(): string
    {
        if (!$this->isSetupRequired()) {
            return 'ok';
        }

        try {
            $progress = $this->progressStorage->load();
            $step     = $progress->getCurrentStepId() ?? 'unknown';

            return sprintf('incomplete setup progress (phase=%s, step=%s)', $progress->getPhase(), $step);
        } catch (Throwable) {
            return 'incomplete setup progress';
        }
    }
}
