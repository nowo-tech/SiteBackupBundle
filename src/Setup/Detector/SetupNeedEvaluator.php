<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;

/**
 * Aggregates detectors — setup is required if any enabled detector says so.
 */
final class SetupNeedEvaluator
{
    /**
     * @param iterable<SetupNeedDetectorInterface> $detectors
     */
    public function __construct(
        private readonly iterable $detectors,
        private readonly bool $setupEnabled = true,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->setupEnabled) {
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
        $reasons = [];
        foreach ($this->detectors as $detector) {
            if ($detector->isSetupRequired()) {
                $reasons[] = $detector->getReason();
            }
        }

        return $reasons;
    }
}
