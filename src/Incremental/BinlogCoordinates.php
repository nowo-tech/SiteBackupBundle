<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;

use function is_int;
use function is_string;
use function preg_match;
use function preg_replace;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

/**
 * A position in a MySQL server's binary log (1.36+): file + byte offset, and the server it belongs to.
 *
 * `serverUuid` identifies the server (`@@server_uuid`): coordinates of another server are never comparable, so a
 * capture or a chain whose UUID differs is a gap. `gtidExecuted` is informational (the GTID set at that point when it
 * is known, else null); positions are what the bundle replays from.
 */
final readonly class BinlogCoordinates
{
    /** Binary log file names: `<basename>.<sequence>` (e.g. `binlog.000042`). */
    public const FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,200}\.(\d{1,10})$/';

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** GTID sets: UUIDs, tags (8.3+), ranges, separators. */
    private const GTID_PATTERN = '/^[0-9A-Za-z:,_-]*$/';

    /** The 4-byte magic header: no event starts before it. */
    public const MIN_POSITION = 4;

    public string $serverUuid;

    public ?string $gtidExecuted;

    public function __construct(
        public string $file,
        public int $position,
        string $serverUuid,
        ?string $gtidExecuted = null,
    ) {
        if (preg_match(self::FILE_PATTERN, $file) !== 1) {
            throw new InvalidArgumentException('Binary log file names must look like "<basename>.<number>".');
        }
        if ($position < self::MIN_POSITION) {
            throw new InvalidArgumentException('Binary log positions start at 4.');
        }
        $serverUuid = strtolower($serverUuid);
        if (preg_match(self::UUID_PATTERN, $serverUuid) !== 1) {
            throw new InvalidArgumentException('The server UUID must be a UUID (@@server_uuid).');
        }
        if ($gtidExecuted !== null) {
            // `SHOW BINARY LOG STATUS` and `@@gtid_executed` wrap long sets over several lines.
            $gtidExecuted = (string) preg_replace('/\s+/', '', $gtidExecuted);
            if (strlen($gtidExecuted) > 65535 || preg_match(self::GTID_PATTERN, $gtidExecuted) !== 1) {
                throw new InvalidArgumentException('The GTID set holds unexpected characters.');
            }
        }

        $this->serverUuid   = $serverUuid;
        $this->gtidExecuted = $gtidExecuted;
    }

    /**
     * The numeric suffix of the file name (`binlog.000042` → 42).
     */
    public function sequence(): int
    {
        return self::sequenceOf($this->file);
    }

    /**
     * The part before the numeric suffix (`binlog.000042` → `binlog`).
     */
    public function basename(): string
    {
        return self::basenameOf($this->file);
    }

    public static function basenameOf(string $file): string
    {
        return substr($file, 0, (int) strrpos($file, '.'));
    }

    public static function sequenceOf(string $file): int
    {
        if (preg_match(self::FILE_PATTERN, $file, $m) !== 1) {
            throw new InvalidArgumentException('Binary log file names must look like "<basename>.<number>".');
        }

        return (int) $m[1];
    }

    /**
     * Same server, file and position (the GTID set is informational and not compared).
     */
    public function equals(self $other): bool
    {
        return $this->serverUuid === $other->serverUuid && $this->file === $other->file && $this->position === $other->position;
    }

    /**
     * @return array{binlog_file: string, binlog_position: int, server_uuid: string, gtid_executed: ?string}
     */
    public function toArray(): array
    {
        return [
            'binlog_file'     => $this->file,
            'binlog_position' => $this->position,
            'server_uuid'     => $this->serverUuid,
            'gtid_executed'   => $this->gtidExecuted,
        ];
    }

    /**
     * Reads the four meta keys; null when they are absent or malformed (a backup without coordinates).
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $file = $data['binlog_file'] ?? null;
        $pos  = $data['binlog_position'] ?? null;
        $uuid = $data['server_uuid'] ?? null;
        $gtid = $data['gtid_executed'] ?? null;
        if (!is_string($file) || !is_int($pos) || !is_string($uuid) || ($gtid !== null && !is_string($gtid))) {
            return null;
        }

        try {
            return new self($file, $pos, $uuid, $gtid);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
