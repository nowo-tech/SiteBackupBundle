<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use function is_file;
use function ltrim;
use function realpath;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

/**
 * Confines the user-supplied `sql_import_path` answer to `.sql` files under the site-backup directory.
 *
 * @internal
 */
final class SqlImportPath
{
    public const ALLOWED_DIR = 'var/site-backup';

    /**
     * @return string|null real path of an existing `.sql` file under {@see ALLOWED_DIR}, or null when rejected
     */
    public static function resolve(string $projectDir, string $path, string $allowedDir = self::ALLOWED_DIR): ?string
    {
        if ($path === '') {
            return null;
        }

        $project   = rtrim($projectDir, '/\\');
        $candidate = $path[0] === '/' ? $path : $project . '/' . ltrim($path, '/');
        $real      = realpath($candidate);
        $root      = realpath($project . '/' . ltrim($allowedDir, '/'));

        if ($real === false || $root === false || !is_file($real)) {
            return null;
        }

        if (!str_starts_with($real, rtrim($root, '/') . '/') || !str_ends_with(strtolower($real), '.sql')) {
            return null;
        }

        return $real;
    }
}
