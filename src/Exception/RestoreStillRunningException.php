<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * "Clear status" while a restore is still running (fresh progress), 1.37+: nothing was cleared.
 */
final class RestoreStillRunningException extends RuntimeException implements SiteBackupException
{
    public function __construct()
    {
        parent::__construct('A restore is still running; its status was not cleared.');
    }
}
