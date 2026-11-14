<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use DateTimeZone;

use function fclose;
use function fgets;
use function fopen;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * First and last event time of decoded binary logs (1.36+): scans `mysqlbinlog --base64-output=DECODE-ROWS` output run
 * with `TZ=UTC`, one line at a time (constant memory). Header lines look like
 * `#251114  9:31:05 server id 1  end_log_pos 1559 CRC32 0x5f2a1e0b <TAB>Query<TAB>thread_id=8 …`.
 *
 * Bookkeeping events (format description, rotate, previous GTIDs, stop) carry the file's creation time, not a write:
 * they are ignored.
 */
final class BinlogEventTimes
{
    private const HEADER = '/^#(\d{2})(\d{2})(\d{2})\s+(\d{1,2}):(\d{2}):(\d{2})\s+server id\s+\d+\s+end_log_pos\s+\d+[^\t]*\t([A-Za-z_-]+)/';

    private const IGNORED = ['Start', 'Format_desc', 'Rotate', 'Previous-GTIDs', 'Previous_gtids', 'Stop'];

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} first, last
     */
    public static function scan(string $decodedFile): array
    {
        $handle = @fopen($decodedFile, 'r');
        if ($handle === false) {
            return [null, null];
        }

        $first = null;
        $last  = null;
        try {
            while (($line = fgets($handle, 65536)) !== false) {
                if (!str_starts_with($line, '#')) {
                    continue;
                }
                $time = self::parse(rtrim($line, "\r\n"));
                if (!$time instanceof DateTimeImmutable) {
                    continue;
                }
                $first ??= $time;
                $last = $time;
            }
        } finally {
            fclose($handle);
        }

        return [$first, $last];
    }

    /**
     * The UTC time of one header line, null for any other line and for bookkeeping events.
     */
    public static function parse(string $line): ?DateTimeImmutable
    {
        if (preg_match(self::HEADER, $line, $m) !== 1) {
            return null;
        }
        foreach (self::IGNORED as $type) {
            if ($m[7] === $type) {
                return null;
            }
        }
        // Artificial events print the epoch.
        if ($m[1] === '70' && $m[2] === '01' && $m[3] === '01') {
            return null;
        }

        $text = sprintf('20%s-%s-%s %02d:%s:%s', $m[1], $m[2], $m[3], (int) $m[4], $m[5], $m[6]);
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC'));

        // createFromFormat() rolls invalid dates over (month 13): such a line is not a header.
        return $time instanceof DateTimeImmutable && $time->format('Y-m-d H:i:s') === $text ? $time : null;
    }
}
