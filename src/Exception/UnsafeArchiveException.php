<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * An archive was refused before extraction (1.28+): link, hard-link or device entries, unsafe names, or a size /
 * ratio / entry-count limit (`backup.extract_limits`) exceeded.
 */
final class UnsafeArchiveException extends RuntimeException implements SiteBackupException
{
}
