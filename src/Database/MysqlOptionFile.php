<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

use function chmod;
use function fclose;
use function fopen;
use function fwrite;
use function implode;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * A `[client]` option file for the Oracle MySQL client tools (1.36+, binary log capture and replay).
 *
 * The binary log tools receive the credentials only through this file (`--defaults-file=<path>`, the first argument),
 * never through argv; the file is created 0600 with `x` mode (it never reuses an existing path) inside a private
 * per-call directory, and the caller removes it in a `finally`.
 */
final class MysqlOptionFile
{
    /**
     * @param array{database: string, host: ?string, port: int, socket: ?string, user: string, pass: string} $connection
     */
    public static function contents(#[SensitiveParameter] array $connection): string
    {
        $lines = ['[client]', 'user=' . self::quote($connection['user'])];
        if ($connection['pass'] !== '') {
            $lines[] = 'password=' . self::quote($connection['pass']);
        }
        if ($connection['socket'] !== null) {
            $lines[] = 'socket=' . self::quote($connection['socket']);
        } else {
            $lines[] = 'host=' . self::quote((string) $connection['host']);
            $lines[] = sprintf('port=%d', $connection['port']);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Writes `<directory>/client.cnf` (0600) for $databaseUrl and returns its path.
     *
     * @throws InvalidArgumentException when the URL is not usable
     */
    public static function write(#[SensitiveParameter] string $databaseUrl, string $directory): string
    {
        $contents = self::contents(MysqlDumpCommandFactory::connection($databaseUrl));
        $path     = $directory . '/client.cnf';
        $handle   = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the MySQL option file.');
        }

        try {
            chmod($path, 0o600);
            fwrite($handle, $contents);
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /**
     * A double-quoted option value; `\` and `"` escaped. Line breaks and other control bytes cannot be expressed.
     */
    private static function quote(#[SensitiveParameter] string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Database credentials with control characters cannot be passed to the MySQL client tools.');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
