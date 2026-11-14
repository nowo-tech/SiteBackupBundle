<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * A binary log capture cannot continue from the given coordinates (1.36+): the next backup of the plan must be a full
 * one. `getReason()` tells why, as a stable machine-readable code (the CLI prints it and the history row records it):
 * `gap_expired`, `server_changed`, `gap_position_invalid`, `chain_closed_by_restore`.
 */
final class BinlogGapException extends RuntimeException implements SiteBackupException
{
    /** The start file is no longer on the server (binlog_expire_logs_seconds, PURGE BINARY LOGS, RESET). */
    public const EXPIRED = 'gap_expired';

    /** The server is another one (`server_uuid` differs: failover, restore from another host, new data directory). */
    public const SERVER_CHANGED = 'server_changed';

    /** The start position is beyond the end of its file (the log was reset or the coordinates are not this server's). */
    public const POSITION_INVALID = 'gap_position_invalid';

    /** A restore ran after the parent was taken (1.36+): the restore is in the binary log; take a new full backup. */
    public const CLOSED_BY_RESTORE = 'chain_closed_by_restore';

    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
