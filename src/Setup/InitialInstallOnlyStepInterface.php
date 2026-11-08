<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * Marker for a host setup step that may run only during the initial install (1.32+, audit IN-I1).
 *
 * A wizard re-opened on a configured instance ({@see SetupWizardReopener}) leaves such steps out, like the built-in
 * admin creation, `database_url` and SQL import steps. Implement it on custom step services that create users, rewrite
 * secrets or replace data.
 */
interface InitialInstallOnlyStepInterface extends SetupStepInterface
{
}
