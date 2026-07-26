<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Attribute;

use Attribute;

/**
 * Mark a controller / action so it stays reachable during restore mode.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ExcludeFromRestore
{
    public const ROUTE_DEFAULT = '_site_backup_exclude';
}
