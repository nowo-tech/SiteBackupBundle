<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\ColdStart;

/**
 * Probes whether the application database schema is reachable.
 */
interface SchemaExistenceCheckerInterface
{
    public function schemaExists(): bool;
}
