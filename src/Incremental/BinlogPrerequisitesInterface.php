<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

/**
 * Checks what binary log incrementals need (1.36+, port): server version, `log_bin`, `binlog_format`,
 * `binlog_row_image`, expiry, grants and the Oracle client tools. Never throws: problems are failing checks.
 */
interface BinlogPrerequisitesInterface
{
    /**
     * @return list<Check>
     */
    public function check(): array;
}
