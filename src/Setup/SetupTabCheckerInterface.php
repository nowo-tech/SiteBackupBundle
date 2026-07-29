<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * Per-tab readiness check declared via YAML `checker:` service id.
 */
interface SetupTabCheckerInterface
{
    public function check(SetupContext $ctx): SetupTabCheckResult;
}
