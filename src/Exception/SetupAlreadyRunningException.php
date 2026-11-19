<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * Another request or console run is advancing or resetting the setup wizard (1.37+). Nothing was done by this call.
 */
final class SetupAlreadyRunningException extends RuntimeException implements SiteBackupException
{
    public function __construct(private readonly int $retryAfterSeconds = 30, ?Throwable $previous = null)
    {
        parent::__construct('Another setup run is in progress; try again later.', 0, $previous);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
