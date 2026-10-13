<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use function array_pop;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Resolves the bundle's own runtime state locations (archive storage dir, restore progress file,
 * backup history file, setup progress file) to paths relative to the project dir.
 *
 * Those paths must never be packed into an archive (archives would nest previous archives) nor
 * overwritten during a restore (the live progress / history would be replaced by stale copies).
 * Paths outside the project dir are irrelevant for both and are dropped.
 */
final class RuntimeStatePaths
{
    /**
     * @param iterable<string|null> $paths absolute, or relative to $projectDir
     *
     * @return list<string> normalized project-relative paths ("/"-separated, no leading/trailing slash)
     */
    public static function relativeTo(string $projectDir, iterable $paths): array
    {
        $project = self::normalize($projectDir);
        $result  = [];

        foreach ($paths as $path) {
            if ($path === null || trim($path) === '') {
                continue;
            }

            $normalized = self::normalize($path);
            if (!self::isAbsolute($normalized)) {
                $normalized = self::normalize($project . '/' . $normalized);
            }

            if ($normalized === $project || !str_starts_with($normalized, $project . '/')) {
                continue;
            }

            $relative = substr($normalized, strlen($project) + 1);
            if ($relative !== '') {
                $result[] = $relative;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * True when $relative is one of $statePaths or lives below one of them.
     *
     * @param list<string> $statePaths
     */
    public static function matches(string $relative, array $statePaths): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        foreach ($statePaths as $statePath) {
            if ($relative === $statePath || str_starts_with($relative, $statePath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forward slashes, no trailing slash, "." / ".." segments collapsed.
     */
    private static function normalize(string $path): string
    {
        $path   = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)?/#', $path, $m) === 1) {
            $prefix = $m[0];
            $path   = substr($path, strlen($prefix));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }

    private static function isAbsolute(string $path): bool
    {
        return preg_match('#^([A-Za-z]:)?/#', $path) === 1;
    }
}
