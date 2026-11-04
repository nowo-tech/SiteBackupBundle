<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;

use function array_search;
use function array_slice;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function strtolower;

/**
 * Builds a `mysqldump` argv + environment from a Doctrine `DATABASE_URL`.
 *
 * - The password travels in `MYSQL_PWD` (environment), never in argv (`ps` / process list).
 * - `--single-transaction`: consistent InnoDB snapshot without locking tables.
 * - `--no-tablespaces`: the application user usually lacks the PROCESS privilege, and
 *   tablespaces are not needed to restore one schema.
 * - No `--routines`: the restore replays the file through PDO, which cannot parse
 *   `DELIMITER` blocks.
 * - Optional `--skip-ssl-verify-server-cert` (MariaDB client only): keep TLS against a MySQL
 *   server that presents a self-signed certificate on a private network.
 * - Argument-injection safe (1.27+, REQ-SEC-004 CG F2): the database name follows a `--` end-of-options marker, and a
 *   database, user, host or socket that starts with `-` or holds control characters is rejected
 *   ({@see DatabaseUrlSafety}).
 *
 * Pure: no I/O, no state.
 */
final class MysqlDumpCommandFactory
{
    /** @var list<string> */
    public const SUPPORTED_SCHEMES = ['mysql', 'mysql2', 'mariadb', 'pdo-mysql'];

    /**
     * @param list<string> $extraOptions appended before the `--` end-of-options marker and the database name
     *                                   (e.g. --column-statistics=0)
     *
     * @throws InvalidArgumentException when the URL is not a usable MySQL / MariaDB URL
     *
     * @return array{command: list<string>, env: array<string, string>}
     */
    public static function fromDatabaseUrl(
        string $databaseUrl,
        bool $skipSslVerifyServerCert = false,
        string $binary = 'mysqldump',
        array $extraOptions = [],
    ): array {
        $connection = self::parse($databaseUrl);

        $command = [$binary, '--single-transaction', '--no-tablespaces'];

        return self::assemble($command, $connection, $skipSslVerifyServerCert, $extraOptions);
    }

    /**
     * `mysql` client argv + environment for the same `DATABASE_URL` (1.28+): schema queries and dump imports.
     * The password stays in `MYSQL_PWD`; the database name follows `--`.
     *
     * @param list<string> $extraOptions appended before the `--` end-of-options marker (e.g. --batch)
     * @param string|null $database another schema on the same server (1.29+, restore drills); [A-Za-z0-9_]{1,64}
     *
     * @throws InvalidArgumentException when the URL is not a usable MySQL / MariaDB URL
     *
     * @return array{command: list<string>, env: array<string, string>, database: string}
     */
    public static function clientFromDatabaseUrl(
        string $databaseUrl,
        bool $skipSslVerifyServerCert = false,
        string $binary = 'mysql',
        array $extraOptions = [],
        ?string $database = null,
    ): array {
        $connection = self::parse($databaseUrl);
        if ($database !== null) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
                throw new InvalidArgumentException('Schema names must match [A-Za-z0-9_]{1,64}.');
            }
            $connection['database'] = $database;
        }
        $spec = self::assemble([$binary], $connection, $skipSslVerifyServerCert, $extraOptions);

        return $spec + ['database' => $connection['database']];
    }

    /**
     * Database name of a `DATABASE_URL` (validated like {@see self::fromDatabaseUrl()}).
     */
    public static function databaseName(string $databaseUrl): string
    {
        return self::parse($databaseUrl)['database'];
    }

    /**
     * Inserts options right before the `--` end-of-options marker of a command built by this factory.
     *
     * @param list<string> $command
     * @param list<string> $options
     *
     * @return list<string>
     */
    public static function withOptions(array $command, array $options): array
    {
        $marker = array_search('--', $command, true);
        if (!is_int($marker)) {
            return [...$command, ...$options];
        }

        return [...array_slice($command, 0, $marker), ...$options, ...array_slice($command, $marker)];
    }

    /**
     * @param list<string> $command binary + leading options
     * @param array{database: string, host: ?string, port: int, socket: ?string, user: string, pass: string} $connection
     * @param list<string> $extraOptions
     *
     * @return array{command: list<string>, env: array<string, string>}
     */
    private static function assemble(array $command, array $connection, bool $skipSslVerifyServerCert, array $extraOptions): array
    {
        if ($skipSslVerifyServerCert) {
            $command[] = '--skip-ssl-verify-server-cert';
        }

        if ($connection['socket'] !== null) {
            $command[] = '--socket=' . $connection['socket'];
        } else {
            $command[] = '--host=' . (string) $connection['host'];
            $command[] = '--port=' . $connection['port'];
        }

        $command[] = '--user=' . $connection['user'];
        foreach ($extraOptions as $option) {
            if ($option !== '') {
                $command[] = $option;
            }
        }
        // End of options: whatever the database name holds, the client reads it as the positional db_name.
        $command[] = '--';
        $command[] = $connection['database'];

        return [
            'command' => $command,
            'env'     => ['MYSQL_PWD' => $connection['pass']],
        ];
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return array{database: string, host: ?string, port: int, socket: ?string, user: string, pass: string}
     */
    private static function parse(string $databaseUrl): array
    {
        $parts = $databaseUrl !== '' ? parse_url($databaseUrl) : false;
        if (!is_array($parts) || !isset($parts['scheme'], $parts['path'])) {
            throw new InvalidArgumentException('DATABASE_URL is missing or invalid.');
        }

        if (!in_array(strtolower($parts['scheme']), self::SUPPORTED_SCHEMES, true)) {
            throw new InvalidArgumentException('DATABASE_URL must use a MySQL / MariaDB scheme (mysql://, mariadb://).');
        }

        $database = rawurldecode(ltrim($parts['path'], '/'));
        if ($database === '') {
            throw new InvalidArgumentException('DATABASE_URL has no database name.');
        }

        $violation = DatabaseUrlSafety::violation($databaseUrl);
        if ($violation !== null) {
            throw new InvalidArgumentException($violation);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $socket = $query['unix_socket'] ?? null;
        $socket = is_string($socket) && $socket !== '' ? $socket : null;

        $host = null;
        if ($socket === null) {
            if (!isset($parts['host']) || $parts['host'] === '') {
                throw new InvalidArgumentException('DATABASE_URL has no host.');
            }
            $host = rawurldecode($parts['host']);
        }

        return [
            'database' => $database,
            'host'     => $host,
            'port'     => (int) ($parts['port'] ?? 3306),
            'socket'   => $socket,
            'user'     => rawurldecode((string) ($parts['user'] ?? '')),
            'pass'     => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }
}
