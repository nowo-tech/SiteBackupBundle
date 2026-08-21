<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Throwable;

/**
 * Aggregates detectors — setup is required if any enabled detector says so.
 *
 * When {@see $shortCircuitWhenDone} is true (default), a present {@code setup.done}
 * marker or a durable store reporting complete skips all detectors. That avoids
 * repeated Doctrine / host catalog probes on every HTTP request after setup.
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
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->setupEnabled) {
            return false;
        }

        if ($this->isAlreadyDone()) {
            return false;
        }

        foreach ($this->detectors as $detector) {
            if ($detector->isSetupRequired()) {
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
        if (!$this->setupEnabled || $this->isAlreadyDone()) {
            return [];
        }

        $reasons = [];
        foreach ($this->detectors as $detector) {
            if ($detector->isSetupRequired()) {
                $reasons[] = $detector->getReason();
            }
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
