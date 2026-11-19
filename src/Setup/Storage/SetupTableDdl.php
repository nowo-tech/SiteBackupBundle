<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use Throwable;

use function class_parents;
use function implode;
use function is_object;
use function is_string;
use function method_exists;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * Portable runtime DDL for the DBAL setup tables (1.37+).
 *
 * Up to 1.36.x the tables were created with hard-coded `CLOB` / `DATETIME` columns, which only SQLite accepts:
 * MySQL / MariaDB reject `CLOB` and PostgreSQL rejects both. The column types now come from the connection's own
 * DBAL platform (`getClobTypeDeclarationSQL()` / `getDateTimeTypeDeclarationSQL()` / `getFloatDeclarationSQL()`,
 * present in DBAL 3 and 4), so the same definition gives `LONGTEXT` + `DATETIME` on MySQL / MariaDB,
 * `TEXT` + `TIMESTAMP(0) WITHOUT TIME ZONE` on PostgreSQL and `CLOB` + `DATETIME` on SQLite (the 1.36 SQLite tables
 * keep working unchanged: nothing is altered or dropped).
 *
 * Doctrine DBAL stays a soft dependency: the connection is duck-typed. A connection without a platform (test fakes,
 * custom wrappers) gets `TEXT` + `DATETIME`, accepted by MySQL, MariaDB and SQLite.
 *
 * Existence: `CREATE TABLE IF NOT EXISTS` on the platforms that support it (MySQL, MariaDB, PostgreSQL, SQLite);
 * plain `CREATE TABLE` elsewhere. When the statement fails (table created by another process at the same moment,
 * a platform without `IF NOT EXISTS`, ...) a `SELECT ... WHERE 1 = 0` probe decides: the table is there → done,
 * otherwise the original error is rethrown.
 *
 * Stateless (static helpers only): the "table ready" memo stays in the storages (per request + optional worker TTL).
 *
 * @internal
 */
final class SetupTableDdl
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_DOUBLE   = 'double';

    private const FALLBACK_TYPES = [
        self::TYPE_TEXT     => 'TEXT',
        self::TYPE_DATETIME => 'DATETIME',
        self::TYPE_DOUBLE   => 'DOUBLE PRECISION',
    ];

    /**
     * Creates the table when it does not exist yet.
     *
     * @param string $tableName table name from bundle configuration (not user input)
     * @param list<string> $columns column definitions with `{text}`, `{datetime}` and `{double}` placeholders
     * @param callable(string): void $execute runs one statement on the connection
     */
    public static function ensureTable(mixed $connection, string $tableName, array $columns, callable $execute): void
    {
        $platform = self::platform($connection);
        $types    = self::types($platform);

        $body = str_replace(
            ['{text}', '{datetime}', '{double}'],
            [$types[self::TYPE_TEXT], $types[self::TYPE_DATETIME], $types[self::TYPE_DOUBLE]],
            implode(",\n    ", $columns),
        );

        $sql = sprintf(
            "CREATE TABLE %s%s (\n    %s\n)",
            self::supportsIfNotExists($platform) ? 'IF NOT EXISTS ' : '',
            $tableName,
            $body,
        );

        try {
            $execute($sql);
        } catch (Throwable $e) {
            if (!self::tableExists($connection, $tableName)) {
                throw $e;
            }
        }
    }

    /**
     * Column types for the connection's platform.
     *
     * @return array{text: string, datetime: string, double: string}
     */
    public static function types(?object $platform): array
    {
        $types = self::FALLBACK_TYPES;
        if ($platform !== null) {
            $types[self::TYPE_TEXT]     = self::declaration($platform, 'getClobTypeDeclarationSQL') ?? $types[self::TYPE_TEXT];
            $types[self::TYPE_DATETIME] = self::declaration($platform, 'getDateTimeTypeDeclarationSQL') ?? $types[self::TYPE_DATETIME];
            $types[self::TYPE_DOUBLE]   = self::declaration($platform, 'getFloatDeclarationSQL') ?? $types[self::TYPE_DOUBLE];
        }

        return $types;
    }

    /**
     * True for MySQL, MariaDB, PostgreSQL and SQLite platforms (DBAL 3 and 4 class names), and when the platform is
     * unknown (the pre-1.37 statement, kept for duck-typed connections).
     */
    public static function supportsIfNotExists(?object $platform): bool
    {
        if ($platform === null) {
            return true;
        }

        $classes = [$platform::class, ...(class_parents($platform) ?: [])];
        foreach ($classes as $class) {
            if (preg_match('/(?:mysql|mariadb|postgre|sqlite)[a-z0-9]*platform$/i', $class) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The connection's DBAL platform, or null when it has none (duck-typed connection) or cannot reach the server.
     */
    public static function platform(mixed $connection): ?object
    {
        if (!is_object($connection) || !method_exists($connection, 'getDatabasePlatform')) {
            return null;
        }

        try {
            $platform = $connection->getDatabasePlatform();
        } catch (Throwable) {
            // DBAL may connect to detect the server version: the CREATE TABLE will surface the real error.
            return null;
        }

        return is_object($platform) ? $platform : null;
    }

    /**
     * Portable existence probe (no information_schema, no schema manager): `SELECT 1 FROM t WHERE 1 = 0`.
     */
    public static function tableExists(mixed $connection, string $tableName): bool
    {
        if (!is_object($connection) || !method_exists($connection, 'executeQuery')) {
            return false;
        }

        try {
            $connection->executeQuery(sprintf('SELECT 1 FROM %s WHERE 1 = 0', $tableName));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function declaration(object $platform, string $method): ?string
    {
        if (!method_exists($platform, $method)) {
            return null;
        }

        try {
            $sql = $platform->{$method}([]);
        } catch (Throwable) {
            return null;
        }

        return is_string($sql) && $sql !== '' ? $sql : null;
    }
}
