<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Attribute;

use Attribute;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;

/**
 * Autoconfigures a service as a setup-need detector (tag nowo.site_backup.setup_need_detector).
 *
 * Detectors feed {@see SetupNeedEvaluator} (site gate).
 * Distinct from tab {@see AsSetupTabChecker} checkers used inside wizard profiles.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSetupNeedDetector
{
    public function __construct(
        public readonly int $priority = 0,
    ) {
    }
}
