<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use function file_put_contents;
use function pack;
use function str_repeat;

/**
 * A structurally valid v4 binary log for tests: the magic number, then events whose 19-byte headers carry the sizes and
 * end positions of $ends (the first event, at 4, is a format description).
 */
final class FakeBinlog
{
    /**
     * @param list<int> $ends end position of every event, ascending; the last one is the file size
     */
    public static function write(string $path, array $ends): void
    {
        $bytes = "\xfebin";
        $start = 4;
        foreach ($ends as $i => $end) {
            $size = $end - $start;
            $bytes .= pack('VCVVVv', 0, $i === 0 ? 15 : 2, 1, $size, $end, 0) . str_repeat("\0", $size - 19);
            $start = $end;
        }
        file_put_contents($path, $bytes);
    }

    /**
     * A decoded event: `# at N` and a header ending at $end.
     */
    public static function event(int $at, int $end, string $type, string $content = '', string $time = '261101  0:30:00'): string
    {
        return '# at ' . $at . "\n#" . $time . ' server id 1  end_log_pos ' . $end . ' CRC32 0x1 ' . "\t" . $type . "\n" . $content;
    }

    /**
     * The format description of a decoded log (labelled with an event start, as mysqlbinlog does).
     */
    public static function start(int $at): string
    {
        return self::event($at, 127, 'Start: binlog v 4, server v 9.7.2', "BINLOG '\nFDE\n'/*!*/;\n");
    }
}
