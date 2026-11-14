<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;

/**
 * Captures the binary logs written since given coordinates (1.36+, port). Framework-free: coordinates in, a captured
 * set of closed binary log files out.
 */
interface BinlogCaptureInterface
{
    /**
     * Closes the current binary log (`FLUSH BINARY LOGS`) and copies every closed log from `$from` to the newly closed
     * one, verbatim (`mysqlbinlog --read-from-remote-server --raw`).
     *
     * @param string $database the schema the backups cover (replays filter on it); [A-Za-z0-9_]{1,64}
     *
     * @throws BinlogGapException when `$from` is no longer on the server or belongs to another server: take a full backup
     * @throws BinlogUnavailableException when the server or the tools fail
     */
    public function capture(BinlogCoordinates $from, string $database): IncrementalArtifact;

    /**
     * Removes the directory of a capture (call it in a `finally` once the files are archived).
     */
    public function discard(IncrementalArtifact $artifact): void;
}
