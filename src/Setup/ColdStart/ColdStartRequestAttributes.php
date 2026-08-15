<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\ColdStart;

/**
 * Request attribute keys used by the cold-start schema gate.
 */
final class ColdStartRequestAttributes
{
    public const SCHEMA_EXISTS = '_nowo_site_backup_schema_exists';

    private function __construct()
    {
    }
}
