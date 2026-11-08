<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Attribute;

use Attribute;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\SetupWizardReopener;

/**
 * Autoconfigures a service as a setup-need detector (tag nowo.site_backup.setup_need_detector).
 *
 * Detectors feed {@see SetupNeedEvaluator} (site gate).
 * Distinct from tab {@see AsSetupTabChecker} checkers used inside wizard profiles.
 *
 * `reopen: true` (v1.31+, tag attribute `reopen`) keeps this detector evaluated after setup is done (the
 * `short_circuit_when_done` fast path skips the others); when it reports setup required, the wizard is re-opened
 * ({@see SetupWizardReopener}). Per-detector form of `setup.reopen_when_detector_requires`:
 * the cost is only this detector's check on every request, so keep it cheap.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSetupNeedDetector
{
    public function __construct(
        public readonly int $priority = 0,
        public readonly bool $reopen = false,
    ) {
    }
}
