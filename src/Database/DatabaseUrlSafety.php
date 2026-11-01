<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use function is_array;
use function is_string;
use function ltrim;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_starts_with;

/**
 * Rejects `DATABASE_URL` components that a dump / client argv would read as options (1.27+, REQ-SEC-004 CG F2):
 * a database name, user, host or unix socket that starts with `-` (e.g. a database named `--result-file=/x.php`), or
 * that contains control characters (decoded). Used when the setup wizard writes `DATABASE_URL` and again when
 * {@see MysqlDumpCommandFactory} builds the `mysqldump` argv.
 *
 * Pure: no I/O, no state.
 */
final class DatabaseUrlSafety
{
    /**
     * @return string|null why the URL is unsafe, or null when every component is acceptable (or the URL does not parse:
     *                     callers validate the shape themselves)
     */
    public static function violation(string $databaseUrl): ?string
    {
        $parts = $databaseUrl !== '' ? parse_url($databaseUrl) : false;
        if (!is_array($parts)) {
            return null;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $components = [
            'database name' => isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : '',
            'user'          => isset($parts['user']) ? rawurldecode($parts['user']) : '',
            'host'          => isset($parts['host']) ? rawurldecode($parts['host']) : '',
            'unix_socket'   => is_string($query['unix_socket'] ?? null) ? $query['unix_socket'] : '',
        ];

        foreach ($components as $label => $value) {
            $violation = self::componentViolation($label, $value);
            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * @return string|null why one decoded component is unsafe for an argv
     */
    public static function componentViolation(string $label, string $value): ?string
    {
        if (str_starts_with($value, '-')) {
            return sprintf('DATABASE_URL %s must not start with "-" (it would be read as a command-line option).', $label);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return sprintf('DATABASE_URL %s must not contain control characters.', $label);
        }

        return null;
    }
}
