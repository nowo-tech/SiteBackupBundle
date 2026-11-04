<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

use function count;
use function sprintf;

/**
 * A path matching the secrets floor or `backup.forbidden_paths` reached the archive file list. Nothing is written.
 */
final class SecretsInArchiveException extends RuntimeException implements SiteBackupException
{
    /**
     * @param list<string> $paths project-relative paths (never file contents)
     */
    public function __construct(private readonly array $paths)
    {
        parent::__construct(sprintf('Refusing to write an archive that contains %d secret path(s) (secrets floor / backup.forbidden_paths).', count($paths)));
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }
}
