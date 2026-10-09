<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;

use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function parse_str;
use function parse_url;
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
 *
 * Pure: no I/O, no state.
 */
final class MysqlDumpCommandFactory
{
    /** @var list<string> */
    public const SUPPORTED_SCHEMES = ['mysql', 'mysql2', 'mariadb', 'pdo-mysql'];

    /**
     * @param list<string> $extraOptions appended before the database name (e.g. --column-statistics=0)
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

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $socket = $query['unix_socket'] ?? null;

        $command = [$binary, '--single-transaction', '--no-tablespaces'];
        if ($skipSslVerifyServerCert) {
            $command[] = '--skip-ssl-verify-server-cert';
        }

        if (is_string($socket) && $socket !== '') {
            $command[] = '--socket=' . $socket;
        } else {
            if (!isset($parts['host']) || $parts['host'] === '') {
                throw new InvalidArgumentException('DATABASE_URL has no host.');
            }
            $command[] = '--host=' . rawurldecode($parts['host']);
            $command[] = '--port=' . (string) ($parts['port'] ?? 3306);
        }

        $command[] = '--user=' . rawurldecode((string) ($parts['user'] ?? ''));
        foreach ($extraOptions as $option) {
            if ($option !== '') {
                $command[] = $option;
            }
        }
        $command[] = $database;

        return [
            'command' => $command,
            'env'     => ['MYSQL_PWD' => rawurldecode((string) ($parts['pass'] ?? ''))],
        ];
    }
}
