<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support\Ports;

use Nowo\SiteBackupBundle\Attribute\AsSetupStep;
use Nowo\SiteBackupBundle\Setup\InitialInstallOnlyStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;

/**
 * DDD host setup step registered by attribute; initial-install only (a re-opened wizard skips it). No Symfony import.
 */
#[AsSetupStep(type: 'host_seed')]
final class HostSeedStep implements InitialInstallOnlyStepInterface
{
    public function getId(): string
    {
        return 'host_seed';
    }

    public function getLabel(): string
    {
        return 'Seed host data';
    }

    public function getUiKind(): string
    {
        return 'auto';
    }

    public function isEnabled(SetupContext $ctx): bool
    {
        return true;
    }

    public function isComplete(SetupContext $ctx): bool
    {
        return false;
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        return SetupStepResult::ok('seeded');
    }
}
