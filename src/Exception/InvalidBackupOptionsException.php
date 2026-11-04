<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\BackupOptions;

/**
 * {@see BackupOptions} that cannot be honoured (invalid values or a combination the
 * configured dumper does not support).
 */
final class InvalidBackupOptionsException extends InvalidArgumentException implements SiteBackupException
{
}
