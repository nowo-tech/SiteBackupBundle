<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Attribute;

use Attribute;
use Nowo\SiteBackupBundle\Setup\InitialInstallOnlyStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;

/**
 * Registers a host {@see SetupStepInterface} service as a setup step type (1.35+, tag
 * `nowo.site_backup.setup_step` with a `type` attribute). Use it in a profile with `- { type: <type> }`.
 *
 * Built-in type names (`admin_user`, `database_url`, `sql_file`, …) and `custom` are reserved: the container build
 * fails, and so does a type used twice in one profile (one shared service, one step id). Implement {@see InitialInstallOnlyStepInterface} on steps that create users,
 * rewrite secrets or replace data, so a re-opened wizard skips them.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSetupStep
{
    public function __construct(
        public readonly string $type,
    ) {
    }
}
