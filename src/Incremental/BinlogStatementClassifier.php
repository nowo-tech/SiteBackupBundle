<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use function array_map;
use function count;
use function explode;
use function implode;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function strtoupper;
use function substr;
use function trim;

/**
 * Attributes one statement of a decoded binary log to a schema (1.36+, pure, best effort by design: it never guesses).
 *
 * Results:
 * - {@see self::SCHEMA}: every target is in the backed-up schema (qualified, or unqualified under that default database);
 * - {@see self::OTHER}: every target is in another schema;
 * - {@see self::SERVER}: server-level statements (accounts, roles, privileges, tablespaces, plugins…), never part of a
 *   schema;
 * - {@see self::CONTROL}: session and transaction statements (`SET` of session / user variables, `BEGIN`, `COMMIT`…);
 * - {@see self::UNKNOWN}: anything else — an unknown verb, targets in several schemas, an unqualified target without
 *   a known default database, a statement on the whole schema (`DROP DATABASE`), a body that would resolve
 *   differently under another default database. Callers fail closed on it.
 *
 * Statement bodies (views, triggers, routines, `CREATE TABLE … SELECT`) are not parsed: a body that reads another
 * schema under the backed-up schema as default database is attributed to the backed-up schema.
 */
final class BinlogStatementClassifier
{
    public const SCHEMA  = 'schema';
    public const OTHER   = 'other';
    public const SERVER  = 'server';
    public const CONTROL = 'control';
    public const UNKNOWN = 'unknown';

    private const NAME = '(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)(?:\s*\.\s*(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+))?';

    private const SERVER_LEVEL = '/^(?:(?:CREATE|ALTER|DROP|RENAME)\s+USER|GRANT|REVOKE|(?:CREATE|DROP)\s+ROLE|SET\s+(?:PASSWORD|DEFAULT\s+ROLE|ROLE|GLOBAL|PERSIST|PERSIST_ONLY|@@GLOBAL)|FLUSH|ALTER\s+INSTANCE|INSTALL|UNINSTALL|(?:CREATE|ALTER|DROP)\s+(?:SERVER|(?:UNDO\s+)?TABLESPACE|LOGFILE\s+GROUP|SPATIAL\s+REFERENCE\s+SYSTEM|RESOURCE\s+GROUP))\b/i';

    private const CONTROL_STATEMENTS = '/^(?:BEGIN|COMMIT|ROLLBACK|START\s+TRANSACTION|SAVEPOINT|RELEASE\s+SAVEPOINT|XA\s+|DELIMITER\b|SET\s+(?:TIMESTAMP|INSERT_ID|LAST_INSERT_ID|@|TRANSACTION|NAMES|CHARACTER|SESSION|COMPLETION_TYPE|AUTOCOMMIT|SQL_LOG_BIN|FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_MODE|TIME_ZONE)\b|SET\s+@)/i';

    /**
     * @return array{0: string, 1: string} result, short reason (never SQL text)
     */
    public static function classify(string $statement, ?string $defaultDb, string $schema): array
    {
        $sql = self::normalise($statement);
        if ($sql === '') {
            return [self::CONTROL, 'empty'];
        }
        if (preg_match(self::CONTROL_STATEMENTS, $sql) === 1 && preg_match(self::SERVER_LEVEL, $sql) !== 1) {
            return [self::CONTROL, 'session'];
        }
        if (preg_match(self::SERVER_LEVEL, $sql) === 1) {
            return [self::SERVER, 'server-level statement'];
        }
        if (preg_match('/^(?:CREATE|ALTER|DROP)\s+(?:DATABASE|SCHEMA)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(' . self::NAME . ')/i', $sql, $m) === 1) {
            return self::unquote($m[1]) === $schema
                ? [self::UNKNOWN, 'statement on the whole schema']
                : [self::OTHER, 'statement on another database'];
        }

        [$verb, $targets, $hasBody] = self::targets($sql);
        if ($verb === null || $targets === []) {
            return [self::UNKNOWN, 'unrecognised statement'];
        }

        $schemas = [];
        foreach ($targets as $target) {
            $db = $target[0] ?? $defaultDb;
            if ($db === null || $db === '') {
                return [self::UNKNOWN, $verb . ' without a database'];
            }
            $schemas[$db] = true;
        }
        if (count($schemas) > 1) {
            return [self::UNKNOWN, $verb . ' across several databases'];
        }
        if (!isset($schemas[$schema])) {
            return [self::OTHER, $verb . ' on another database'];
        }
        // Under another default database, unqualified names in a body (or a foreign key) would resolve elsewhere.
        if ($defaultDb !== $schema && $hasBody) {
            return [self::UNKNOWN, $verb . ' with a body under another default database'];
        }

        return [self::SCHEMA, $verb];
    }

    /**
     * The statement without comments (versioned comments unwrapped), on one line.
     */
    public static function normalise(string $statement): string
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $statement)) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $lines[] = $line;
        }
        $sql = implode(' ', $lines);
        // /*!50001 X */ and /*!\C latin1 */ -> X; plain comments removed.
        $sql = (string) preg_replace('~/\*!\\\\C\s+\w+\s*\*/~', ' ', $sql);
        $sql = (string) preg_replace('~/\*!\d*\s?(.*?)\*/~s', ' $1 ', $sql);
        $sql = (string) preg_replace('~/\*.*?\*/~s', ' ', $sql);

        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * @return array{0: ?string, 1: list<array{0: ?string, 1: string}>, 2: bool} verb, [db|null, name] targets, has a body
     */
    private static function targets(string $sql): array
    {
        $n   = self::NAME;
        $sql = (string) preg_replace('/\bDEFINER\s*=\s*\S+/i', ' ', $sql);
        $sql = (string) preg_replace('/\b(?:ALGORITHM\s*=\s*\w+|SQL\s+SECURITY\s+\w+|OR\s+REPLACE|TEMPORARY|IF\s+NOT\s+EXISTS|IF\s+EXISTS|ONLINE|OFFLINE|NO_WRITE_TO_BINLOG|LOCAL)\b/i', ' ', $sql);
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        $patterns = [
            'CREATE TABLE'   => '/^CREATE TABLE (' . $n . ')(.*)$/i',
            'ALTER TABLE'    => '/^ALTER (?:IGNORE )?TABLE (' . $n . ')(.*)$/i',
            'TRUNCATE'       => '/^TRUNCATE (?:TABLE )?(' . $n . ')()$/i',
            'CREATE INDEX'   => '/^CREATE (?:UNIQUE |FULLTEXT |SPATIAL )?INDEX ' . $n . ' ON (' . $n . ')()/i',
            'DROP INDEX'     => '/^DROP INDEX ' . $n . ' ON (' . $n . ')()/i',
            'CREATE VIEW'    => '/^(?:CREATE|ALTER) VIEW (' . $n . ')(.*)$/i',
            'CREATE TRIGGER' => '/^CREATE TRIGGER (' . $n . ') (?:BEFORE|AFTER) \w+ ON (' . $n . ')(.*)$/i',
            'DROP TRIGGER'   => '/^DROP TRIGGER (' . $n . ')()$/i',
            'CREATE ROUTINE' => '/^CREATE (?:PROCEDURE|FUNCTION) (' . $n . ')(.*)$/i',
            'ALTER ROUTINE'  => '/^(?:ALTER|DROP) (?:PROCEDURE|FUNCTION) (' . $n . ')()/i',
            'CREATE EVENT'   => '/^(?:CREATE|ALTER) EVENT (' . $n . ')(.*)$/i',
            'DROP EVENT'     => '/^DROP EVENT (' . $n . ')()$/i',
        ];
        foreach ($patterns as $verb => $pattern) {
            if (preg_match($pattern, $sql, $m) !== 1) {
                continue;
            }
            $names = $verb === 'CREATE TRIGGER' ? [$m[1], $m[2]] : [$m[1]];
            $rest  = $verb === 'CREATE TRIGGER' ? ($m[3] ?? '') : $m[2];
            $body  = match ($verb) {
                'CREATE VIEW', 'CREATE TRIGGER', 'CREATE ROUTINE', 'CREATE EVENT' => true,
                'CREATE TABLE', 'ALTER TABLE'                                     => preg_match('/\b(?:SELECT|LIKE)\b/i', $rest) === 1 || self::unqualifiedReference($rest),
                default                                                           => false,
            };

            return [$verb, array_map(self::split(...), $names), $body];
        }

        if (preg_match('/^DROP (TABLE|VIEW) ((?:' . $n . ')(?: ?, ?' . $n . ')*)(?: RESTRICT| CASCADE)?$/i', $sql, $m) === 1) {
            return ['DROP ' . strtoupper($m[1]), self::list($m[2]), false];
        }
        if (preg_match('/^(?:ANALYZE|OPTIMIZE|REPAIR|CHECK) TABLE ((?:' . $n . ')(?: ?, ?' . $n . ')*)/i', $sql, $m) === 1) {
            return ['TABLE MAINTENANCE', self::list($m[1]), false];
        }
        if (preg_match('/^RENAME TABLE ((?:' . $n . ') TO (?:' . $n . ')(?: ?, ?(?:' . $n . ') TO (?:' . $n . '))*)$/i', $sql, $m) === 1) {
            $names = [];
            preg_match_all('/' . $n . '/', (string) preg_replace('/\bTO\b/i', ',', $m[1]), $all);
            foreach ($all[0] as $name) {
                $names[] = self::split($name);
            }

            return ['RENAME TABLE', $names, false];
        }

        return [null, [], false];
    }

    /**
     * A foreign key to an unqualified table.
     */
    private static function unqualifiedReference(string $rest): bool
    {
        return preg_match('/\bREFERENCES\s+(?!(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)\s*\.)/i', $rest) === 1;
    }

    /**
     * @return list<array{0: ?string, 1: string}>
     */
    private static function list(string $names): array
    {
        preg_match_all('/' . self::NAME . '/', $names, $all);

        return array_map(self::split(...), $all[0]);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function split(string $name): array
    {
        if (preg_match('/^((?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+))\s*\.\s*((?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+))$/', trim($name), $m) === 1) {
            return [self::unquote($m[1]), self::unquote($m[2])];
        }

        return [null, self::unquote(trim($name))];
    }

    private static function unquote(string $identifier): string
    {
        $identifier = trim($identifier);
        if (str_starts_with($identifier, '`')) {
            $identifier = str_replace('``', '`', substr($identifier, 1, -1));
        }

        // Schema names compare exactly (case-sensitive on Linux servers).
        return $identifier;
    }
}
