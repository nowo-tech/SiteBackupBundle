<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * A remote target operation failed (1.29+). Messages are redacted; the host's original exception is only chained when
 * its message holds no credentials.
 */
final class RemoteTargetException extends RuntimeException implements SiteBackupException
{
    public const VERIFY_MISMATCH = 'verify_mismatch';
}
