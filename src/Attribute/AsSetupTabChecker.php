<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Attribute;

use Attribute;

/**
 * Autoconfigures a service as a setup tab checker (tag nowo.site_backup.setup_tab_checker).
 * Bind the checker to a tab with YAML `checker: Service\\Id\\Or\\Fqcn`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSetupTabChecker
{
}
