<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function explode;
use function mb_substr;
use function min;
use function preg_match;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Runs the Oracle `mysqlbinlog` (1.36+). Internal: argv never holds a credential (the remote read gets
 * `--defaults-file`), the process runs with `TZ=UTC` so printed and `--stop-datetime` times are UTC.
 */
final class MysqlBinlogTool
{
    public function __construct(
        private readonly string $binary = 'mysqlbinlog',
        private readonly int $timeoutSeconds = 600,
    ) {
    }

    /**
     * `x.y.z` of the Oracle tool.
     *
     * @throws BinlogUnavailableException when the tool is missing, is the MariaDB one or is older than 8.0.26
     */
    public function assertUsable(): string
    {
        $process = new Process([$this->binary, '--version']);
        try {
            ProcessDeadline::run($process, min(30, $this->timeoutSeconds));
        } catch (ProcessTimedOutException) {
            throw new BinlogUnavailableException('mysqlbinlog --version timed out.');
        }
        $line    = trim(explode("\n", trim($process->getOutput()))[0]);
        $version = $process->isSuccessful() ? MysqlToolVersion::oracleClient($line) : null;
        if (!MysqlToolVersion::supports($version)) {
            throw new BinlogUnavailableException(sprintf('Binary log incrementals need the Oracle mysqlbinlog %s+ ("%s" is not usable).', MysqlToolVersion::MINIMUM, $this->binary));
        }

        return (string) $version;
    }

    /**
     * Copies closed binary logs verbatim into `<$targetDir>/<name>`.
     *
     * @param list<string> $names
     *
     * @throws BinlogGapException when the first file was purged meanwhile
     * @throws BinlogUnavailableException
     */
    public function fetchRaw(string $optionFile, array $names, string $targetDir, ?callable $tick = null): void
    {
        $this->run([
            $this->binary, '--defaults-file=' . $optionFile, '--read-from-remote-server', '--raw',
            '--result-file=' . $targetDir . '/', ...$names,
        ], 'reading the binary logs from the server', $tick);
    }

    /**
     * Decodes local binary logs into $target.
     *
     * @param list<string> $files local paths, in order
     * @param int|null $startPosition applies to the first file only
     * @param DateTimeImmutable|null $stopBefore events at or after this time are left out
     * @param bool $headersOnly event headers only (`--base64-output=DECODE-ROWS`), to read event times
     */
    public function decode(array $files, string $target, ?int $startPosition, ?string $database, ?DateTimeImmutable $stopBefore, bool $headersOnly, ?callable $tick = null): void
    {
        $command = [$this->binary];
        if ($headersOnly) {
            $command[] = '--base64-output=DECODE-ROWS';
        } else {
            // The server already holds these GTIDs: without this the replay would skip every transaction.
            $command[] = '--skip-gtids';
        }
        if ($database !== null) {
            $command[] = '--database=' . $database;
        }
        if ($startPosition !== null) {
            $command[] = '--start-position=' . $startPosition;
        }
        if ($stopBefore instanceof DateTimeImmutable) {
            $command[] = '--stop-datetime=' . $stopBefore->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $command[] = '--result-file=' . $target;

        $this->run([...$command, ...$files], 'decoding the binary logs', $tick);
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $what, ?callable $tick): void
    {
        $process = new Process($command, null, ['TZ' => 'UTC']);
        try {
            ProcessDeadline::run($process, $this->timeoutSeconds, $tick);
        } catch (ProcessTimedOutException) {
            throw new BinlogUnavailableException(sprintf('mysqlbinlog timed out after %d seconds (%s).', $this->timeoutSeconds, $what));
        }
        if ($process->isSuccessful()) {
            return;
        }

        $error = trim($process->getErrorOutput());
        // ER_SOURCE_FATAL_ERROR_READING_BINLOG (1236): the file left the server between the check and the read.
        if (str_contains($error, 'Could not find first log file') || preg_match('/\b1236\b/', $error) === 1) {
            throw new BinlogGapException(BinlogGapException::EXPIRED, 'A binary log was purged from the server during the capture: take a full backup.');
        }

        throw new BinlogUnavailableException(sprintf('mysqlbinlog failed (%s): %s', $what, mb_substr(SecretRedactor::redact($error), 0, 300)));
    }
}
