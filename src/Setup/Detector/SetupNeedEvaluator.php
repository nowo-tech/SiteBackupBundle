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
 * When {@see $reopenWhenDetectorRequires} is also true (v1.15+, default false), that short-circuit no longer
 * hides detectors: they are still evaluated, and if one reports setup is required the done markers
 * (file + durable store) are cleared and progress is reset via {@see SetupWizardReopener}, so the
 * wizard can run again (e.g. platform catalogs were wiped). This costs one detector pass per request.
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
     * @param bool $allowReopen v1.31: evaluate the `reopen: true` detectors when setup is done (the site gate only)
     */
    public function isSetupRequired(bool $allowReopen = false): bool
    {
        if (!$this->setupEnabled) {
            return false;
        }

        $done = $this->isAlreadyDone();
        if ($done && !$this->reopenWhenDetectorRequires) {
            if (!$allowReopen) {
                return false;
            }
            foreach ($this->reopenDetectors as $detector) {
                if ($this->reopenDetectorRequires($detector)) {
                    $this->reopener?->reopen();

                    return true;
                }
            }

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
     * A `reopen: true` detector: a throw counts as "not required" (logged at warning level), so a failing probe on a
     * done site neither reopens the wizard nor answers 500.
     */
    private function reopenDetectorRequires(SetupNeedDetectorInterface $detector): bool
    {
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
