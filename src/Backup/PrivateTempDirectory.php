<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use RuntimeException;

use function bin2hex;
use function chmod;
use function mkdir;
use function random_bytes;
use function rtrim;
use function sys_get_temp_dir;

/**
 * Owner-only staging directories for backup / verify / restore (1.27+, REQ-SEC-004 CG L1): the staging tree holds a
 * plaintext DB dump and .env files, so it is created 0700 under an unpredictable name (128-bit random suffix) with an
 * atomic mkdir that fails when the path already exists (no pre-planted directory or symlink is reused). The explicit
 * chmod covers a umask that strips owner bits; no process-wide umask() is touched (unsafe in threaded worker SAPIs).
 */
final class PrivateTempDirectory
{
    private const ATTEMPTS = 5;

    public static function create(string $prefix, ?string $baseDir = null): string
    {
        $base = rtrim($baseDir ?? sys_get_temp_dir(), '/\\');

        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $path = $base . '/' . $prefix . bin2hex(random_bytes(16));
            if (@mkdir($path, 0o700)) {
                chmod($path, 0o700);

                return $path;
            }
        }

        throw new RuntimeException('Unable to create a private temporary directory for the backup staging tree.');
    }

    /**
     * {@see self::create()} plus the `.owner` tag (host + pid) that lets the next backup / cleanup remove the directory
     * at once when this process died (1.36+, PP-L5: option files of a killed worker do not wait for the age limit).
     */
    public static function createOwned(string $prefix, ?string $baseDir = null): string
    {
        $path = self::create($prefix, $baseDir);
        @file_put_contents($path . '/.owner', gethostname() . ' ' . getmypid());

        return $path;
    }
}
