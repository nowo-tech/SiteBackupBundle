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
use function hrtime;
use function implode;
use function is_file;
use function is_readable;
use function min;
use function preg_match;
use function sprintf;
use function stripos;
use function strlen;
use function trim;
use function version_compare;

/**
 * Streams a SQL dump into the `DATABASE_URL` database with the `mysql` client (1.28+).
 *
 * The client reads the file through a shell redirect, so PHP never holds the dump in memory (the 1.27 PDO import
 * read the whole file into one string). The password stays in `MYSQL_PWD`; the argv holds no secret.
 *
 * EN-M1: the client would otherwise run client commands found in the file (`\! cmd`, `\T file`, `source …`). The
 * importer always disables them: `--system-command=OFF` for Oracle clients (8.0.40+, 8.4.3+, 9.1+) and `--sandbox`
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

    /** Minimum MariaDB client per major.minor for `--sandbox`; later lines all have it. */
    private const MARIADB_SANDBOX = ['10.5' => '10.5.25', '10.6' => '10.6.18', '10.11' => '10.11.8', '11.0' => '11.0.6', '11.1' => '11.1.5', '11.2' => '11.2.4', '11.3' => '11.3.2', '11.4' => '11.4.2'];

    /** Minimum Oracle client per major.minor for `--system-command=OFF`; 9.1+ all have it. */
    private const ORACLE_SYSTEM_COMMAND = ['8.0' => '8.0.40', '8.4' => '8.4.3'];

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
            throw new DumpFailedException('This mysql client cannot disable client commands (\\!, \\T, source) in imported dumps: use Oracle mysql 8.0.40+ / 8.4.3+ / 9.1+ (--system-command=OFF) or MariaDB 10.5.25+ / 10.6.18+ / 10.11.8+ / 11.4.2+ (--sandbox).');
        }

        return $option;
    }

    /**
     * @return string|null `--sandbox`, `--system-command=OFF` or null (unsupported) for a `mysql --version` line
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

        return self::atLeast($m[1] . '.' . $m[2], $m[1] . '.' . $m[2] . '.' . $m[3], self::ORACLE_SYSTEM_COMMAND, '9.1') ? '--system-command=OFF' : null;
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
        if (!is_file($file) || !is_readable($file)) {
            throw new DumpFailedException('SQL import: the dump file is missing or not readable.');
        }

        $lock = $this->operationLock?->acquire();
        try {
            $this->run($file, $tick, $lock);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param (callable(): void)|null $tick
     */
    private function run(string $file, ?callable $tick, ?BackupOperationLockHandle $lock): void
    {
        try {
            MysqlDumpCommandFactory::databaseName((string) $this->databaseUrl);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('SQL import: ' . $e->getMessage(), 0, $e);
        }
        $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl((string) $this->databaseUrl, $this->skipSslVerifyServerCert, $this->clientBinary, ['--batch', $this->safetyOption()]);

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
