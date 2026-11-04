<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Enum;

/**
 * What the built-in dumper does with one table (1.28+).
 *
 * - {@see self::Skip}: the table is neither created nor filled by the dump. Restoring into an empty database leaves
 *   it missing; restoring over an existing database leaves the current table as it was.
 * - {@see self::StructureOnly}: `CREATE TABLE` (and its triggers) without rows. The recommended rule for bulk or
 *   volatile data.
 */
enum TableRule: string
{
    case Skip          = 'skip';
    case StructureOnly = 'structure';
}
