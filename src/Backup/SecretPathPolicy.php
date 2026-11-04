<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use Nowo\SiteBackupBundle\Exception\SecretsInArchiveException;

use function basename;
use function explode;
use function fnmatch;
use function implode;
use function in_array;
use function ltrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Paths that never enter an archive and are never written by a restore (1.28+, owner decision D-20261104-15).
 *
 * The **floor** is fixed (hosts cannot remove it):
 * - every `.env*` file at any depth, except the committed templates `.env.dist` / `.env.example` at the project root;
 * - `config/secrets/**` (Symfony vault, incl. decrypt keys), `var/secrets/**` (Halite / doctrine-encrypt keys),
 *   `config/jwt/**`;
 * - every `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*.jks`, `id_rsa*`, `id_ed25519*` anywhere under the project;
 * - `auth.json`, `.npmrc`, `.git-credentials`, `.netrc`, `.pgpass`, `.my.cnf` at any depth.
 *
 * `backup.forbidden_paths` adds fnmatch patterns (project-relative) on top. Matching is case-insensitive on the
 * normalised path (backslashes → `/`, no leading `./` or `/`).
 *
 * Pure: no I/O, no mutable state.
 */
final class SecretPathPolicy
{
    /** @var list<string> */
    public const FLOOR_DIRECTORIES = ['config/secrets', 'var/secrets', 'config/jwt'];

    /** @var list<string> */
    public const FLOOR_EXTENSIONS = ['.key', '.pem', '.p12', '.pfx', '.jks'];

    /** @var list<string> */
    public const FLOOR_BASENAME_PREFIXES = ['.env', 'id_rsa', 'id_ed25519'];

    /** @var list<string> */
    public const FLOOR_BASENAMES = ['auth.json', '.npmrc', '.git-credentials', '.netrc', '.pgpass', '.my.cnf'];

    /** @var list<string> committed templates, allowed at the project root only (owner decision Q-O1) */
    public const TEMPLATE_CARVE_OUTS = ['.env.dist', '.env.example'];

    /** @var list<string> */
    private readonly array $extraPatterns;

    /**
     * @param list<string> $forbiddenPaths `backup.forbidden_paths` (added to the floor)
     */
    public function __construct(array $forbiddenPaths = [])
    {
        $patterns = [];
        foreach ($forbiddenPaths as $pattern) {
            $pattern = self::normalize($pattern);
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }
        $this->extraPatterns = $patterns;
    }

    public function isForbidden(string $relativePath): bool
    {
        $path = self::normalize($relativePath);
        if ($path === '') {
            return false;
        }

        return self::matchesFloor($path) || $this->matchesExtra($path);
    }

    /**
     * @param iterable<string> $relativePaths
     *
     * @return list<string> the forbidden ones
     */
    public function forbiddenAmong(iterable $relativePaths): array
    {
        $hits = [];
        foreach ($relativePaths as $path) {
            if ($this->isForbidden($path)) {
                $hits[] = $path;
            }
        }

        return $hits;
    }

    /**
     * @param iterable<string> $relativePaths
     *
     * @throws SecretsInArchiveException when any path is forbidden
     */
    public function assertNoSecrets(iterable $relativePaths): void
    {
        $hits = $this->forbiddenAmong($relativePaths);
        if ($hits !== []) {
            throw new SecretsInArchiveException($hits);
        }
    }

    public static function matchesFloor(string $relativePath): bool
    {
        $path     = strtolower(self::normalize($relativePath));
        $basename = basename($path);

        if ($path === '') {
            return false;
        }

        foreach (self::FLOOR_DIRECTORIES as $dir) {
            if ($path === $dir || str_starts_with($path, $dir . '/')) {
                return true;
            }
        }

        if (in_array($basename, self::FLOOR_BASENAMES, true)) {
            return true;
        }

        foreach (self::FLOOR_EXTENSIONS as $extension) {
            if (str_ends_with($basename, $extension)) {
                return true;
            }
        }

        foreach (self::FLOOR_BASENAME_PREFIXES as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                // Only the committed templates at the project root are allowed.
                return !($prefix === '.env' && !str_contains($path, '/') && in_array($basename, self::TEMPLATE_CARVE_OUTS, true));
            }
        }

        return false;
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        $segments = [];
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function matchesExtra(string $path): bool
    {
        $lower = strtolower($path);
        foreach ($this->extraPatterns as $pattern) {
            $pattern = strtolower($pattern);
            if (fnmatch($pattern, $lower) || fnmatch($pattern . '/*', $lower) || fnmatch('*/' . $pattern, $lower)) {
                return true;
            }
        }

        return false;
    }
}
