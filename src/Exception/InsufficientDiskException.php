<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

use function sprintf;

/**
 * Preflight: not enough free space for staging + archive (`backup.preflight_disk_check`).
 */
final class InsufficientDiskException extends RuntimeException implements SiteBackupException
{
    public function __construct(string $which, int $neededBytes, int $freeBytes)
    {
        parent::__construct(sprintf('Not enough free disk space in %s: about %d bytes needed, %d free.', $which, $neededBytes, $freeBytes));
    }
}
