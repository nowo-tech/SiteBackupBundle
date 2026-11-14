<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Restore;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\RuntimeStatePaths;
use Nowo\SiteBackupBundle\Backup\SecretPathPolicy;
use Nowo\SiteBackupBundle\Incremental\FileManifest;

use function dirname;
use function ltrim;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;

/**
 * Which project paths a chain restore may write or delete (1.36+): the same rules as {@see RestoreOrchestrator}
 * (restore.protected_paths, `var/site-backup/`, the runtime state files, the secrets floor), plus path shape and a
 * real-path check of the parent directory (a parent swapped for a link never leads outside the project).
 */
final class RestorePathGuard
{
    /**
     * @param list<string> $protectedRelativePaths
     * @param list<string> $runtimeStateRelativePaths
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $protectedRelativePaths,
        private readonly array $runtimeStateRelativePaths,
        private readonly SecretPathPolicy $secretPolicy,
    ) {
    }

    /**
     * The rules of the archiver's restore: runtime state paths and secrets floor as configured.
     *
     * @param list<string> $protectedRelativePaths restore.protected_paths
     */
    public static function fromArchiver(string $projectDir, array $protectedRelativePaths, BackupArchiver $archiver): self
    {
        return new self($projectDir, $protectedRelativePaths, $archiver->getRuntimeStateRelativePaths(), $archiver->getSecretPathPolicy());
    }

    /**
     * Why $relative must not be touched, or null when it may.
     */
    public function refusal(string $relative): ?string
    {
        try {
            FileManifest::assertPath($relative);
        } catch (InvalidArgumentException) {
            return 'unsafe path';
        }
        $relative = str_replace('\\', '/', $relative);
        foreach ($this->protectedRelativePaths as $protected) {
            $protected = rtrim(str_replace('\\', '/', ltrim($protected, '/')), '/');
            if ($protected !== '' && ($relative === $protected || str_starts_with($relative, $protected . '/'))) {
                return 'protected path';
            }
        }
        if (str_starts_with($relative, 'var/site-backup/') || RuntimeStatePaths::matches($relative, $this->runtimeStateRelativePaths)) {
            return 'runtime state';
        }
        if ($this->secretPolicy->isForbidden($relative)) {
            return 'secret path';
        }

        return null;
    }

    /**
     * True when the closest existing parent directory of $relative resolves inside the project.
     */
    public function parentStaysInside(string $relative): bool
    {
        $project = realpath($this->projectDir);
        if ($project === false) {
            return false;
        }
        $dir = dirname(rtrim($this->projectDir, '/\\') . '/' . $relative);
        // Terminates: "/" always resolves.
        while (($real = realpath($dir)) === false) {
            $dir = dirname($dir);
        }

        return $real === $project || str_starts_with($real, $project . '/');
    }
}
