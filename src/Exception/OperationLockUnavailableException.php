<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * The operation lock could not be taken because its store failed (lock store down, lock file not writable), 1.37+.
 *
 * Not a conflict: nothing holds the lock, so it is never answered with 409 / `CONFLICT_001`. Nothing was started.
 */
final class OperationLockUnavailableException extends RuntimeException implements SiteBackupException
{
    public function __construct(string $message = 'The operation lock store is unavailable; nothing was started.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
