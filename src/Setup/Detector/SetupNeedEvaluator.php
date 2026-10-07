<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Throwable;

/**
 * Aggregates detectors — setup is required if any enabled detector says so.
 *
 * When {@see $shortCircuitWhenDone} is true (default), a present {@code setup.done}
 * marker or a durable store reporting complete skips all detectors. That avoids
 * repeated Doctrine / host catalog probes on every HTTP request after setup.
 *
 * When {@see $reopenWhenDetectorRequires} is also true (v1.15+, default false), that short-circuit no longer
 * hides detectors: they are still evaluated, and if one reports setup is required the done markers
 * (file + durable store) are cleared and progress is reset via {@see SetupWizardReopener}, so the
 * wizard can run again (e.g. platform catalogs were wiped). This costs one detector pass per request.
 */
final class SetupNeedEvaluator
{
    /**
     * @param iterable<SetupNeedDetectorInterface> $detectors
     */
    public function __construct(
        private readonly iterable $detectors,
        private readonly bool $setupEnabled = true,
        private readonly bool $shortCircuitWhenDone = true,
        private readonly ?SetupMarkerManager $markers = null,
        private readonly ?DurableSetupDoneStoreInterface $durableDoneStore = null,
        private readonly bool $reopenWhenDetectorRequires = false,
        private readonly ?SetupWizardReopener $reopener = null,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->setupEnabled) {
            return false;
        }

        $done = $this->isAlreadyDone();
        if ($done && !$this->reopenWhenDetectorRequires) {
            return false;
        }

        foreach ($this->detectors as $detector) {
            if ($detector->isSetupRequired()) {
                if ($done) {
                    $this->reopener?->reopen();
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function getReasons(): array
    {
        if (!$this->setupEnabled) {
            return [];
        }

        $done = $this->isAlreadyDone();
        if ($done && !$this->reopenWhenDetectorRequires) {
            return [];
        }

        $reasons = [];
        foreach ($this->detectors as $detector) {
            if ($detector->isSetupRequired()) {
                $reasons[] = $detector->getReason();
            }
        }

        if ($done && $reasons !== []) {
            $this->reopener?->reopen();
        }

        return $reasons;
    }

    /**
     * Fast path: file marker or host durable store says setup completed.
     */
    private function isAlreadyDone(): bool
    {
        if (!$this->shortCircuitWhenDone) {
            return false;
        }

        if ($this->markers instanceof SetupMarkerManager && $this->markers->isDone()) {
            return true;
        }

        if (!$this->durableDoneStore instanceof DurableSetupDoneStoreInterface) {
            return false;
        }

        try {
            return $this->durableDoneStore->isDone();
        } catch (Throwable) {
            return false;
        }
    }
}
