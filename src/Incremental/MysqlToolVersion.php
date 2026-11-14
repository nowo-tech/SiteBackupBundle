<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use function preg_match;
use function stripos;
use function version_compare;

/**
 * Version checks for the binary log features (1.36+): they need Oracle MySQL 8.0.26+ (server and client tools);
 * MariaDB servers and the MariaDB client tools write and read another binary log dialect.
 */
final class MysqlToolVersion
{
    /** `--source-data`, `SOURCE_LOG_FILE` and the replica-era statements. */
    public const MINIMUM = '8.0.26';

    /**
     * `x.y.z` of an Oracle client `--version` line (`mysqldump  Ver 9.7.2 for Linux …`), null for MariaDB or an
     * unknown line.
     */
    public static function oracleClient(string $versionLine): ?string
    {
        if (stripos($versionLine, 'mariadb') !== false) {
            return null;
        }
        if (preg_match('/\bVer (\d+\.\d+\.\d+)/', $versionLine, $m) !== 1 && preg_match('/Distrib (\d+\.\d+\.\d+)/', $versionLine, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * `x.y.z` of a MySQL server `VERSION()` (`9.7.2`, `8.0.36-log`), null for MariaDB or an unknown value.
     */
    public static function oracleServer(string $version): ?string
    {
        if (stripos($version, 'mariadb') !== false || preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public static function supports(?string $version, string $minimum = self::MINIMUM): bool
    {
        return $version !== null && version_compare($version, $minimum, '>=');
    }
}
