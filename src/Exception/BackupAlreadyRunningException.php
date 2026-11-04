<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * Another backup, import, delete or restore holds the operation lock.
 */
final class BackupAlreadyRunningException extends RuntimeException implements SiteBackupException
{
    public function __construct(private readonly int $retryAfterSeconds = 300)
    {
        parent::__construct('Another backup or restore operation is running; try again later.');
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
