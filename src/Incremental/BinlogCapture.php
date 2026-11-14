<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\PrivateTempDirectory;
use Nowo\SiteBackupBundle\Database\MysqlOptionFile;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function array_map;
use function count;
use function filesize;
use function hash_file;
use function is_file;
use function preg_match;
use function sprintf;

/**
 * Default {@see BinlogCaptureInterface} (1.36+): the Oracle `mysql` client for the statements
 * ({@see BinlogServerInterface}) and `mysqlbinlog --read-from-remote-server --raw` for the files.
 *
 * 1. the server must be the one of `$from` (`server_uuid`), and `$from` must still be on it (`SHOW BINARY LOGS`, its
 *    position within the file), else {@see BinlogGapException};
 * 2. `FLUSH BINARY LOGS`: every log up to the one just closed is immutable; the capture ends at the end of that one;
 * 3. the closed logs from `$from` on are copied whole (so positions stay the server's), checked against the sizes the
 *    server reported and hashed;
 * 4. their event headers are decoded (`TZ=UTC`) for the first / last event time after `$from`.
 *
 * Credentials reach the tools only through an option file (0600, private directory, removed in a `finally`). Every
 * file lives in a per-call owner-only directory under `backup.work_dir`; a failed capture removes it.
 */
final class BinlogCapture implements BinlogCaptureInterface
{
    public function __construct(
        private readonly BinlogServerInterface $server,
        #[SensitiveParameter]
        private readonly ?string $databaseUrl,
        private readonly MysqlBinlogTool $tool = new MysqlBinlogTool(),
        private readonly ?string $workDir = null,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function capture(BinlogCoordinates $from, string $database, ?callable $tick = null): IncrementalArtifact
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new InvalidArgumentException('Schema names must match [A-Za-z0-9_]{1,64}.');
        }
        $this->tool->assertUsable();
        $version = MysqlToolVersion::oracleServer($this->server->version());
        if (!MysqlToolVersion::supports($version)) {
            throw new BinlogUnavailableException(sprintf('Binary log incrementals need a MySQL %s+ server (MariaDB is not supported).', MysqlToolVersion::MINIMUM));
        }
        $uuid = $this->server->serverUuid();
        if ($uuid !== $from->serverUuid) {
            throw new BinlogGapException(BinlogGapException::SERVER_CHANGED, 'The server is not the one these coordinates belong to (server_uuid differs): take a full backup.');
        }
        self::locate($from, $this->server->binaryLogs());

        $this->server->flushBinaryLogs();
        $logs   = $this->server->binaryLogs();
        $closed = self::closedFrom($from, $logs);
        // Empty: $from sits at the end of the last closed log, nothing was written since.
        $end = $closed === [] ? null : $closed[count($closed) - 1];

        $directory = PrivateTempDirectory::create('nowo-site-backup-binlog-', $this->workDir);
        try {
            if ($end === null) {
                return new IncrementalArtifact($from, $from, $database, [], $directory, null, null, $this->clock->now());
            }

            $raw = $directory . '/raw';
            $this->filesystem->mkdir($raw, 0o700);
            $credentials = $directory . '/credentials';
            $this->filesystem->mkdir($credentials, 0o700);
            try {
                $optionFile = MysqlOptionFile::write((string) $this->databaseUrl, $credentials);
            } catch (InvalidArgumentException $e) {
                throw new BinlogUnavailableException('Binary log tools: ' . $e->getMessage(), 0, $e);
            }
            try {
                $this->tool->fetchRaw($optionFile, array_map(static fn (array $l): string => $l['name'], $closed), $raw, $tick);
            } finally {
                $this->filesystem->remove($credentials);
            }

            $files = [];
            foreach ($closed as $log) {
                $path = $raw . '/' . $log['name'];
                $size = is_file($path) ? filesize($path) : false;
                if ($size !== $log['size']) {
                    throw new BinlogUnavailableException(sprintf('The copy of %s is incomplete (%s of %d bytes).', $log['name'], $size === false ? 'missing' : (string) $size, $log['size']));
                }
                $files[] = new CapturedBinlog($log['name'], $path, $size, (string) hash_file('sha256', $path));
            }

            $events = $directory . '/events.txt';
            $start  = $files[0]->name === $from->file ? $from->position : null;
            $this->tool->decode(array_map(static fn (CapturedBinlog $f): string => $f->path, $files), $events, $start, null, null, true, $tick);
            [$first, $last] = BinlogEventTimes::scan($events);
            $this->filesystem->remove($events);

            $to = new BinlogCoordinates($end['name'], $end['size'], $uuid);

            return new IncrementalArtifact($from, $to, $database, $files, $directory, $first, $last, $this->clock->now());
        } catch (Throwable $e) {
            $this->filesystem->remove($directory);

            throw $e;
        }
    }

    public function discard(IncrementalArtifact $artifact): void
    {
        $this->filesystem->remove($artifact->directory);
    }

    /**
     * $from must name a log still on the server, at a position within it.
     *
     * @param list<array{name: string, size: int}> $logs
     *
     * @throws BinlogGapException
     */
    public static function locate(BinlogCoordinates $from, array $logs): void
    {
        foreach ($logs as $log) {
            if ($log['name'] !== $from->file) {
                continue;
            }
            if ($from->position > $log['size']) {
                throw new BinlogGapException(BinlogGapException::POSITION_INVALID, sprintf('Position %d is beyond the end of %s: take a full backup.', $from->position, $from->file));
            }

            return;
        }

        throw new BinlogGapException(BinlogGapException::EXPIRED, sprintf('%s is no longer on the server (expired or purged): take a full backup.', $from->file));
    }

    /**
     * The closed logs (every one but the active last) that hold events after $from, oldest first; contiguous.
     *
     * @param list<array{name: string, size: int}> $logs after FLUSH BINARY LOGS
     *
     * @throws BinlogGapException|BinlogUnavailableException
     *
     * @return list<array{name: string, size: int}>
     */
    public static function closedFrom(BinlogCoordinates $from, array $logs): array
    {
        self::locate($from, $logs);
        $closed = [];
        $found  = false;
        for ($i = 0, $n = count($logs) - 1; $i < $n; ++$i) {
            $log = $logs[$i];
            if ($log['name'] === $from->file) {
                $found = true;
                if ($from->position < $log['size']) {
                    $closed[] = $log;
                }
                continue;
            }
            if ($found) {
                $closed[] = $log;
            }
        }
        if (!$found) {
            throw new BinlogUnavailableException(sprintf('%s is still the active binary log after FLUSH BINARY LOGS.', $from->file));
        }

        $previous = $from->sequence();
        $basename = $from->basename();
        foreach ($closed as $index => $log) {
            $sequence = BinlogCoordinates::sequenceOf($log['name']);
            $expected = $sequence === $previous + 1 || ($index === 0 && $sequence === $previous);
            if (BinlogCoordinates::basenameOf($log['name']) !== $basename || !$expected) {
                throw new BinlogGapException(BinlogGapException::EXPIRED, 'The binary logs on the server are not contiguous from the start coordinates: take a full backup.');
            }
            $previous = $sequence;
        }

        return $closed;
    }
}
