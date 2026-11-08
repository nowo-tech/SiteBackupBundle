<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\SetupDbDoneGuard;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates detectors — setup is required if any enabled detector says so.
 *
 * When {@see $shortCircuitWhenDone} is true (default), a present {@code setup.done}
 * marker or a durable store reporting complete skips all detectors. That avoids
 * repeated Doctrine / host catalog probes on every HTTP request after setup.
 *
 * When {@see $reopenWhenDetectorRequires} is also true (v1.15+, default false), every detector is a reopen detector:
 * after done they are still evaluated, and if one reports setup is required the done markers
 * (file + durable store) are cleared and progress is reset via {@see SetupWizardReopener}, so the
 * wizard can run again (e.g. platform catalogs were wiped). Since 1.32 (IN-L2) it goes through the same guards as the
 * `reopen: true` detectors below, and {@see DoctrineConnectDetector} / {@see DoctrineSchemaEmptyDetector} never count
 * as reopen signals (a database blip is not a missing setup).
 *
 * {@see $reopenDetectors} (v1.31+, tag attribute `reopen: true`) is the per-detector form: after done, only those
 * detectors are evaluated, and one that requires setup re-opens the wizard the same way. That pass runs only when the
 * caller allows it ($allowReopen: the site gate, on a main GET / HEAD request outside the setup, panel and excluded
 * paths), never from the wizard, the panel, commands or {@see SetupDbDoneGuard}. A reopen
 * detector that throws counts as "not required" and is logged (IN-M1 / IN-L1): a probe failure never reopens a done
 * wizard and never turns into a 500. Contract: a reopen detector returns false when it cannot decide (never fail-true).
 */
final class SetupNeedEvaluator
{
    /**
     * @param iterable<SetupNeedDetectorInterface> $detectors
     * @param iterable<SetupNeedDetectorInterface> $reopenDetectors Detectors tagged `reopen: true` (also in $detectors)
     */
    public function __construct(
        private readonly iterable $detectors,
        private readonly bool $setupEnabled = true,
        private readonly bool $shortCircuitWhenDone = true,
        private readonly ?SetupMarkerManager $markers = null,
        private readonly ?DurableSetupDoneStoreInterface $durableDoneStore = null,
        private readonly bool $reopenWhenDetectorRequires = false,
        private readonly ?SetupWizardReopener $reopener = null,
        private readonly iterable $reopenDetectors = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param bool $allowReopen v1.31: evaluate the reopen detectors when setup is done (the site gate only). Since 1.32
     *                          (IN-L2) this also gates `reopen_when_detector_requires`
     */
    public function isSetupRequired(bool $allowReopen = false): bool
    {
        if (!$this->setupEnabled) {
            return false;
        }

        if (!$this->isAlreadyDone()) {
            foreach ($this->detectors as $detector) {
                if ($detector->isSetupRequired()) {
                    return true;
                }
            }

            return false;
        }

        if (!$allowReopen) {
            return false;
        }

        foreach ($this->reopenWhenDetectorRequires ? $this->detectors : $this->reopenDetectors as $detector) {
            if ($this->reopenDetectorRequires($detector)) {
                $this->reopener?->reopen();

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

        // Done: no reasons and no reopen from here (only the site gate reopens, IN-M1 / IN-L2).
        if ($this->isAlreadyDone()) {
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
     * A `reopen: true` detector: a throw counts as "not required" (logged at warning level), so a failing probe on a
     * done site neither reopens the wizard nor answers 500.
     */
    private function reopenDetectorRequires(SetupNeedDetectorInterface $detector): bool
    {
        // IN-L2: the built-in Doctrine detectors are cold-start signals (connect fails, no tables). On a done instance
        // they report a database blip, not a missing setup, so they never re-open the wizard.
        if ($detector instanceof DoctrineConnectDetector || $detector instanceof DoctrineSchemaEmptyDetector) {
            return false;
        }

        try {
            return $detector->isSetupRequired();
        } catch (Throwable $e) {
            $this->logger?->warning('nowo_site_backup: reopen detector {detector} failed; treated as "setup not required".', [
                'detector'  => $detector::class,
                'exception' => $e,
            ]);

            return false;
        }
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
