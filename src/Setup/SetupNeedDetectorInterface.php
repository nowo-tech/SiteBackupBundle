<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;

/**
 * Site-gate probe: should visitors be sent to the setup wizard?
 *
 * Register with {@see AsSetupNeedDetector}
 * (tag {@code nowo.site_backup.setup_need_detector}). Aggregated by
 * {@see SetupNeedEvaluator} (OR).
 *
 * Not the same as a wizard tab {@see SetupTabCheckerInterface} bound via YAML
 * {@code checker:} under {@code setup.profiles.*.tabs}.
 */
interface SetupNeedDetectorInterface
{
    public function isSetupRequired(): bool;

    public function getReason(): string;
}
