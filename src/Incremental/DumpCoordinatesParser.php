<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use function fclose;
use function fgets;
use function fopen;
use function preg_match;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

/**
 * Reads the binary log coordinates `mysqldump --source-data=2` writes at the top of a dump (1.36+):
 *
 * - `-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000002', SOURCE_LOG_POS=1480;` (8.0.23+ clients;
 *   older clients wrote `CHANGE MASTER TO MASTER_LOG_FILE=…, MASTER_LOG_POS=…`);
 * - with `--set-gtid-purged=COMMENTED`: `/* SET @@GLOBAL.GTID_PURGED='+<gtid set>';*\/`, the GTID set of the
 *   snapshot (it may span several lines).
 *
 * Only the header is read: the scan stops at the first `CREATE TABLE` / `INSERT` or after 4 MiB.
 */
final class DumpCoordinatesParser
{
    private const MAX_HEADER_BYTES = 4 * 1024 * 1024;

    private const COORDINATES = "/^-- CHANGE (?:REPLICATION SOURCE|MASTER) TO (?:SOURCE|MASTER)_LOG_FILE='([^']+)', (?:SOURCE|MASTER)_LOG_POS=(\\d+);/";

    /**
     * @return array{file: string, position: int, gtid: ?string}|null null when the dump has no coordinates
     */
    public static function parse(string $dumpFile): ?array
    {
        $handle = @fopen($dumpFile, 'r');
        if ($handle === false) {
            return null;
        }

        $read   = 0;
        $coords = null;
        $gtid   = null;
        $inGtid = null;
        try {
            while (($line = fgets($handle, 1 << 20)) !== false) {
                $read += strlen($line);
                if ($inGtid !== null) {
                    $end = strpos($line, "'");
                    $inGtid .= $end === false ? $line : substr($line, 0, $end);
                    if ($end !== false) {
                        $gtid   = $inGtid;
                        $inGtid = null;
                    }
                } elseif (str_contains($line, "GTID_PURGED='")) {
                    $rest = substr($line, (int) strpos($line, "GTID_PURGED='") + 13);
                    $rest = substr($rest, 0, 1) === '+' ? substr($rest, 1) : $rest;
                    $end  = strpos($rest, "'");
                    if ($end === false) {
                        $inGtid = $rest;
                    } else {
                        $gtid = substr($rest, 0, $end);
                    }
                } elseif (preg_match(self::COORDINATES, $line, $m) === 1) {
                    $coords = ['file' => $m[1], 'position' => (int) $m[2]];
                } elseif (preg_match('/^(?:CREATE TABLE|INSERT INTO|LOCK TABLES)\b/', $line) === 1) {
                    break;
                }
                if ($read > self::MAX_HEADER_BYTES) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $coords === null ? null : $coords + ['gtid' => $gtid];
    }
}
