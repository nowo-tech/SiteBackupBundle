<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function array_map;
use function escapeshellarg;
use function implode;
use function is_file;
use function is_readable;
use function preg_match;
use function sprintf;
use function strlen;
use function trim;

/**
 * Streams a SQL dump into the `DATABASE_URL` database with the `mysql` client (1.28+).
 *
 * The client reads the file through a shell redirect, so PHP never holds the dump in memory (the 1.27 PDO import
 * read the whole file into one string). The password stays in `MYSQL_PWD`; the argv holds no secret.
 */
final class DumpImporter
{
    public function __construct(
        private readonly ?string $databaseUrl,
        private readonly string $clientBinary = 'mysql',
        private readonly bool $skipSslVerifyServerCert = false,
        private readonly int $timeoutSeconds = 600,
    ) {
    }

    /**
     * @param (callable(): void)|null $tick called while the client runs
     *
     * @throws DumpFailedException
     */
    public function import(string $file, ?callable $tick = null): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new DumpFailedException('SQL import: the dump file is missing or not readable.');
        }

        try {
            $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl((string) $this->databaseUrl, $this->skipSslVerifyServerCert, $this->clientBinary, ['--batch']);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('SQL import: ' . $e->getMessage(), 0, $e);
        }

        $commandLine = 'exec ' . implode(' ', array_map(escapeshellarg(...), $spec['command'])) . ' < ' . escapeshellarg($file);
        $process     = Process::fromShellCommandline($commandLine, null, $spec['env']);

        try {
            ProcessDeadline::run($process, $this->timeoutSeconds, $tick);
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
