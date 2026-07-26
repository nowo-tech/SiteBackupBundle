<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Detector;

use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;

final class MarkerFileDetector implements SetupNeedDetectorInterface
{
    public function __construct(
        private readonly SetupMarkerManager $markers,
        private readonly bool $requireDoneMarker = true,
        private readonly bool $enabled = true,
    ) {
    }

    public function isSetupRequired(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->markers->isRequiredMarked()) {
            return true;
        }

        return (bool) ($this->requireDoneMarker && !$this->markers->isDone())

        ;
    }

    public function getReason(): string
    {
        if ($this->markers->isRequiredMarked()) {
            return 'setup.required marker present';
        }

        if ($this->requireDoneMarker && !$this->markers->isDone()) {
            return 'setup.done marker missing';
        }

        return 'ok';
    }
}
