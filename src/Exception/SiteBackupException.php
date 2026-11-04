<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use Throwable;

/**
 * Marker for every exception the backup engine throws on purpose (1.28+).
 *
 * Messages never carry credentials, argv, SQL, absolute paths outside the project or raw subprocess output.
 */
interface SiteBackupException extends Throwable
{
}
