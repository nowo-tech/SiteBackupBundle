<?php

declare(strict_types=1);

namespace App\Setup;

use Nowo\SiteBackupBundle\Attribute\AsSetupTabChecker;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerInterface;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckResult;

/**
 * Demo tab checker for profile demo_features (YAML checker:).
 */
#[AsSetupTabChecker]
final class DemoSeedTabChecker implements SetupTabCheckerInterface
{
    public const SEED_RELATIVE = 'var/site-backup/demo-seed.ok';

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function check(SetupContext $ctx): SetupTabCheckResult
    {
        if (is_file($this->seedPath())) {
            return SetupTabCheckResult::ok('demo seed already present');
        }

        return SetupTabCheckResult::waitingForInput('demo.check.seed');
    }

    public function seedPath(): string
    {
        return $this->projectDir . '/' . self::SEED_RELATIVE;
    }
}
