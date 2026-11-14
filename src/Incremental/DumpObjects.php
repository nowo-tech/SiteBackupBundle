<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use function fclose;
use function fgets;
use function fopen;
use function preg_match_all;
use function str_replace;
use function str_starts_with;
use function strtoupper;

use const PREG_SET_ORDER;

/**
 * The schema objects a `mysqldump` file re-creates (1.36+, PP-H1): `TABLE name`, `VIEW name`, `TRIGGER name` and, when
 * the chain's full backup was taken with `dumpRoutines` (`--routines --events`), `PROCEDURE`, `FUNCTION`, `EVENT`.
 *
 * Reads only lines that start with `CREATE` or a versioned comment (constant memory; row data is never parsed).
 */
final class DumpObjects
{
    /**
     * @return array<string, true> `TYPE name` => true
     */
    public static function parse(string $dumpFile): array
    {
        $handle = @fopen($dumpFile, 'r');
        if ($handle === false) {
            return [];
        }

        $objects = [];
        try {
            while (($line = fgets($handle, 1 << 16)) !== false) {
                if (!str_starts_with($line, 'CREATE ') && !str_starts_with($line, '/*!')) {
                    continue;
                }
                preg_match_all('~(?:^CREATE\s+(TABLE|VIEW)|/\*!\d+\s+(?:CREATE\s+)?(VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)|^CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?(PROCEDURE|FUNCTION))\s+`((?:[^`]|``)+)`~', $line, $m, PREG_SET_ORDER);
                foreach ($m as $match) {
                    $type                                                     = strtoupper($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]));
                    $objects[$type . ' ' . str_replace('``', '`', $match[4])] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        return $objects;
    }
}
