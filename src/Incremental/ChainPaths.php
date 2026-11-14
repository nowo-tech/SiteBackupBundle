<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use function str_starts_with;

/**
 * Payload paths reserved by chain backups (1.36+, PP-L9): the bundle's own files live under `.nowo-site-backup/`, so
 * project files can never collide with them. `MANIFEST.json` and `database/dump.sql` are the 1.x layout.
 */
final class ChainPaths
{
    public const DIRECTORY = '.nowo-site-backup/';

    public const DELETED = self::DIRECTORY . 'DELETED.json';

    public const BINLOG_DIRECTORY = self::DIRECTORY . 'binlog/';

    public const EVENTS = self::BINLOG_DIRECTORY . CapturedBinlog::EVENTS;

    public const DUMP = 'database/dump.sql';

    public const MANIFEST = 'MANIFEST.json';

    /**
     * True for a payload path that is the bundle's, not a project file.
     */
    public static function isReserved(string $path): bool
    {
        return $path === self::MANIFEST || $path === self::DUMP || str_starts_with($path, self::DIRECTORY);
    }
}
