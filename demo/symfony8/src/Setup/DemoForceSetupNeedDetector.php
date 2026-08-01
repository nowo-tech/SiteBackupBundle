<?php

declare(strict_types=1);

namespace App\Setup;

use Nowo\SiteBackupBundle\Attribute\AsSetupNeedDetector;
use Nowo\SiteBackupBundle\Setup\SetupNeedDetectorInterface;

/**
 * Demo gate detector (v1.8): force setup when var/site-backup/demo-force-setup exists.
 * Toggle from the homepage — distinct from tab {@see DemoSeedTabChecker}.
 */
#[AsSetupNeedDetector(priority: 50)]
final class DemoForceSetupNeedDetector implements SetupNeedDetectorInterface
{
    public const FLAG_RELATIVE = 'var/site-backup/demo-force-setup';

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function isSetupRequired(): bool
    {
        return is_file($this->flagPath());
    }

    public function getReason(): string
    {
        return 'demo force-setup flag present (toggle from homepage)';
    }

    public function flagPath(): string
    {
        return $this->projectDir . '/' . self::FLAG_RELATIVE;
    }
}
