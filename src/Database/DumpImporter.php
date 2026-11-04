<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Lock\BackupOperationLockHandle;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function array_map;
use function escapeshellarg;
use function fclose;
use function fgets;
use function fopen;
use function hrtime;
use function implode;
use function is_file;
use function is_readable;
use function min;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function trim;
use function version_compare;

/**
 * Streams a SQL dump into the `DATABASE_URL` database with the `mysql` client (1.28+).
 *
 * The client reads the file through a shell redirect, so PHP never holds the dump in memory (the 1.27 PDO import
 * read the whole file into one string). The password stays in `MYSQL_PWD`; the argv holds no secret.
 *
 * EN-M1: the client would otherwise run client commands found in the file (`\! cmd`, `\T file`, `source …`). The
 * importer always disables them: `--commands=OFF` for Oracle clients (8.0.43+, 8.4.6+, 9.4+; it disables `system`, `tee`,
 * `source`, `connect`, … — `--system-command=OFF` alone left `\T` and `source` usable, re-audit EN-M1) and `--sandbox`
 * for MariaDB clients (10.5.25+, 10.6.18+, 10.11.8+, 11.0.6+, 11.1.5+, 11.2.4+, 11.4.2+, 11.5+). A client that
 * supports neither is refused. Imports take the operation lock when it is wired (EN-I9).
 */
final class DumpImporter
{
    public function __construct(
        private readonly ?string $databaseUrl,
        private readonly string $clientBinary = 'mysql',
        private readonly bool $skipSslVerifyServerCert = false,
        private readonly int $timeoutSeconds = 600,
        private readonly ?BackupOperationLock $operationLock = null,
    ) {
    }

    /**
     * EO-R1 (best effort): a statement whose target is `db`.`table` with a database other than the drill schema.
     * Group 1 / 2 = the backtick-quoted or bare qualifier.
     */
    private const QUALIFIED_TARGET = '#^\s*(?:/\*!\d*\s*)?(?:(?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*(?:\s+INTO)?|UPDATE(?:\s+(?:LOW_PRIORITY|IGNORE))*|DELETE(?:\s+(?:LOW_PRIORITY|QUICK|IGNORE))*\s+FROM|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES?|RENAME\s+TABLE|(?:CREATE|DROP|ALTER)(?:\s+(?:OR\s+REPLACE|TEMPORARY|ONLINE|IGNORE))*\s+(?:TABLE|VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT|INDEX\s+\S+\s+ON)(?:\s+IF\s+(?:NOT\s+)?EXISTS)?|LOAD\s+DATA\b.*?\bINTO\s+TABLE)\s+(?:`((?:[^`]|``)+)`|([A-Za-z0-9_$]+))\s*\.#i';

    private const DATABASE_SWITCH = '#^\s*(?:/\*!\d*\s*)?(?:USE\b|\\\\u\b|\\\\r\b|CONNECT\b|(?:CREATE|DROP|ALTER)\s+(?:DATABASE|SCHEMA)\b|-- Current Database:)#i';

    /** Minimum MariaDB client per major.minor for `--sandbox`; later lines all have it. */
    private const MARIADB_SANDBOX = ['10.5' => '10.5.25', '10.6' => '10.6.18', '10.11' => '10.11.8', '11.0' => '11.0.6', '11.1' => '11.1.5', '11.2' => '11.2.4', '11.3' => '11.3.2', '11.4' => '11.4.2'];

    /** Minimum Oracle client per major.minor for `--commands=OFF` (8.0.43, 8.4.6, 9.4.0); later lines all have it. */
    private const ORACLE_COMMANDS = ['8.0' => '8.0.43', '8.4' => '8.4.6'];

    /**
     * The client option that disables client commands in the imported file (EN-M1).
     *
     * @throws DumpFailedException when the client cannot disable them
     */
    public function safetyOption(): string
    {
        $process = new Process([$this->clientBinary, '--version']);
        try {
            ProcessDeadline::run($process, min(30, $this->timeoutSeconds));
        } catch (ProcessTimedOutException) {
            throw new DumpFailedException('mysql --version timed out.');
        }
        $version = trim($process->getOutput());
        if (!$process->isSuccessful() || $version === '') {
            throw new DumpFailedException(sprintf('The mysql client "%s" is not usable.', $this->clientBinary));
        }

        $option = self::safetyOptionFor($version);
        if ($option === null) {
            throw new DumpFailedException('This mysql client cannot disable client commands (\\!, \\T, source) in imported dumps: use Oracle mysql 8.0.43+ / 8.4.6+ / 9.4+ (--commands=OFF) or MariaDB 10.5.25+ / 10.6.18+ / 10.11.8+ / 11.4.2+ (--sandbox).');
        }

        return $option;
    }

    /**
     * @return string|null `--sandbox`, `--commands=OFF` or null (unsupported) for a `mysql --version` line
     */
    public static function safetyOptionFor(string $version): ?string
    {
        if (stripos($version, 'mariadb') !== false) {
            if (preg_match('/(\d+)\.(\d+)\.(\d+)-MariaDB/i', $version, $m) !== 1) {
                return null;
            }

            return self::atLeast($m[1] . '.' . $m[2], $m[1] . '.' . $m[2] . '.' . $m[3], self::MARIADB_SANDBOX, '11.5') ? '--sandbox' : null;
        }

        if (preg_match('/Distrib (\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1 && preg_match('/Ver (\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return null;
        }

        return self::atLeast($m[1] . '.' . $m[2], $m[1] . '.' . $m[2] . '.' . $m[3], self::ORACLE_COMMANDS, '9.4') ? '--commands=OFF' : null;
    }

    /**
     * @param array<string, string> $minimums major.minor => minimum full version
     */
    private static function atLeast(string $line, string $full, array $minimums, string $allFrom): bool
    {
        if (isset($minimums[$line])) {
            return version_compare($full, $minimums[$line], '>=');
        }

        return version_compare($line . '.0', $allFrom . '.0', '>=');
    }

    /**
     * @param (callable(): void)|null $tick called while the client runs
     *
     * @throws BackupAlreadyRunningException|DumpFailedException
     */
    public function import(string $file, ?callable $tick = null): void
    {
        $this->importInto($file, null, $tick);
    }

    /**
     * Imports into another schema of the same server (1.29+, restore drills). The dump must not select its own
     * database (mysqldump with one database name does not).
     *
     * @param (callable(): void)|null $tick
     *
     * @throws BackupAlreadyRunningException|DumpFailedException
     */
    public function importInto(string $file, ?string $schema, ?callable $tick = null): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new DumpFailedException('SQL import: the dump file is missing or not readable.');
        }

        if ($schema !== null) {
            // EO-M3: a dump that switches database would write outside the target schema (the live one).
            self::assertSingleDatabaseDump($file, $schema);
        }

        $lock = $this->operationLock?->acquire();
        try {
            $this->run($file, $schema, $tick, $lock);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param (callable(): void)|null $tick
     */
    private function run(string $file, ?string $schema, ?callable $tick, ?BackupOperationLockHandle $lock): void
    {
        try {
            MysqlDumpCommandFactory::databaseName((string) $this->databaseUrl);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('SQL import: ' . $e->getMessage(), 0, $e);
        }
        $options = ['--batch', $this->safetyOption()];
        if ($schema !== null) {
            // EO-M3: the client itself skips statements for any other database (Oracle and MariaDB clients).
            $options[] = '--one-database';
        }
        $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl((string) $this->databaseUrl, $this->skipSslVerifyServerCert, $this->clientBinary, $options, $schema);

        $commandLine = 'exec ' . implode(' ', array_map(escapeshellarg(...), $spec['command'])) . ' < ' . escapeshellarg($file);
        $process     = Process::fromShellCommandline($commandLine, null, $spec['env']);

        try {
            $refreshed = hrtime(true);
            ProcessDeadline::run($process, $this->timeoutSeconds, static function () use ($tick, $lock, &$refreshed): void {
                if ($lock !== null && hrtime(true) - $refreshed > 5_000_000_000) {
                    $lock->refresh();
                    $refreshed = hrtime(true);
                }
                if ($tick !== null) {
                    $tick();
                }
            });
        } catch (ProcessTimedOutException) {
            throw new DumpFailedException(sprintf('SQL import timed out after %d seconds (process_timeout).', $this->timeoutSeconds));
        }
        if (!$process->isSuccessful()) {
            throw new DumpFailedException('SQL import failed: ' . self::safeError($process->getErrorOutput()));
        }
    }

    /**
     * EO-M3: refuses dumps that select or create databases (`USE`, `\u`, `CREATE/DROP/ALTER DATABASE|SCHEMA`,
     * `CONNECT`, `/*!… USE …`, `mysqldump --databases` / `--all-databases` output). Streaming scan of line starts.
     * EO-R1: with `$schema`, also refuses statements whose target is qualified with another database
     * (`INSERT INTO app.t …`), which `--one-database` does not stop. Best effort: names qualified inside a statement
     * body (views, triggers, routines, sub-queries) are not parsed, hence the restricted drill account.
     *
     * @throws DumpFailedException
     */
    public static function assertSingleDatabaseDump(string $file, ?string $schema = null): void
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new DumpFailedException('SQL import: the dump file is missing or not readable.');
        }

        try {
            $atLineStart = true;
            $lineNumber  = 0;
            while (($piece = fgets($handle, 65536)) !== false) {
                if ($atLineStart) {
                    ++$lineNumber;
                    if (preg_match(self::DATABASE_SWITCH, $piece) === 1) {
                        throw new DumpFailedException(sprintf('The dump selects or creates a database (line %d): refused for an import into another schema.', $lineNumber));
                    }
                    if ($schema !== null && preg_match(self::QUALIFIED_TARGET, $piece, $m) === 1
                        && strtolower($m[1] !== '' ? str_replace('``', '`', $m[1]) : ($m[2] ?? '')) !== strtolower($schema)) {
                        throw new DumpFailedException(sprintf('The dump writes to a table of another database (line %d): refused for an import into another schema.', $lineNumber));
                    }
                }
                $atLineStart = str_ends_with($piece, "\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Creates an empty schema (fails when it exists: a drill never reuses, and so never drops, an existing schema).
     *
     * @throws DumpFailedException
     */
    public function createSchema(string $schema): void
    {
        $this->execute(sprintf('CREATE DATABASE `%s`', self::schemaName($schema)));
    }

    /**
     * @throws DumpFailedException
     */
    public function dropSchema(string $schema): void
    {
        $this->execute(sprintf('DROP DATABASE IF EXISTS `%s`', self::schemaName($schema)));
    }

    public function databaseName(): string
    {
        try {
            return MysqlDumpCommandFactory::databaseName((string) $this->databaseUrl);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('SQL import: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function schemaName(string $schema): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $schema) !== 1) {
            throw new DumpFailedException('Schema names must match [A-Za-z0-9_]{1,64}.');
        }

        return $schema;
    }

    private function execute(string $sql): void
    {
        try {
            $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl((string) $this->databaseUrl, $this->skipSslVerifyServerCert, $this->clientBinary, ['--batch', '--execute=' . $sql]);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('SQL import: ' . $e->getMessage(), 0, $e);
        }

        $process = new Process($spec['command'], null, $spec['env']);
        try {
            ProcessDeadline::run($process, $this->timeoutSeconds);
        } catch (ProcessTimedOutException) {
            throw new DumpFailedException('SQL statement timed out.');
        }
        if (!$process->isSuccessful()) {
            $error = self::safeError($process->getErrorOutput());
            if (str_starts_with($error, 'ERROR 1049 ')) {
                // EO-R1: the client connects to the database named in the URL before running the statement.
                $error .= ': the database named in the connection URL must already exist';
            }

            throw new DumpFailedException('SQL statement failed: ' . $error);
        }
    }

    /**
     * The client quotes the failing statement (row data): keep only "ERROR <code> (<sqlstate>) at line <n>".
     */
    public static function safeError(string $stderr): string
    {
        if (preg_match('/ERROR \d+ \([0-9A-Z]{5}\)(?: at line \d+)?/', $stderr, $m) === 1) {
            return $m[0];
        }

        return sprintf('mysql client error (%d bytes of output withheld)', strlen(trim($stderr)));
    }
}
