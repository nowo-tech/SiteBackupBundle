<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use InvalidArgumentException;

use function array_map;

/**
 * The result of one binary log capture (1.36+): the closed binary log files that hold every event after `$from` up
 * to `$to`, copied into `$directory` (owner-only), with the time of the first and last event after `$from`.
 *
 * `$files` is empty when nothing was written since `$from`. The caller owns `$directory` and removes it
 * ({@see BinlogCaptureInterface::discard()}); the archiver does that itself for incremental backups.
 */
final readonly class IncrementalArtifact
{
    /** The closed binary log files themselves (every schema of the server): `binlog_capture: raw`. */
    public const RAW = 'raw';

    /** Only the backed-up schema's events, decoded ({@see BinlogSchemaFilter}): the default. */
    public const FILTERED = 'filtered';

    /**
     * @param list<CapturedBinlog> $files in server order (contiguous sequence numbers); in filtered mode they describe
     *                                    the source logs (their paths are gone once the capture is archived)
     * @param CapturedBinlog|null $events filtered mode: the schema-only decoded events (name `events.sql`)
     */
    public function __construct(
        public BinlogCoordinates $from,
        public BinlogCoordinates $to,
        public string $database,
        public array $files,
        public string $directory,
        public ?DateTimeImmutable $firstEventAt = null,
        public ?DateTimeImmutable $lastEventAt = null,
        public ?DateTimeImmutable $capturedAt = null,
        public string $mode = self::RAW,
        public ?CapturedBinlog $events = null,
        public int $droppedEvents = 0,
    ) {
        if ($mode !== self::RAW && $mode !== self::FILTERED) {
            throw new InvalidArgumentException('The capture mode is raw or filtered.');
        }
        if ($mode === self::FILTERED && $files !== [] && $events === null) {
            throw new InvalidArgumentException('A filtered capture has an events file.');
        }
        if ($from->serverUuid !== $to->serverUuid) {
            throw new InvalidArgumentException('Both ends of a capture belong to the same server.');
        }
        $previous = null;
        foreach ($files as $file) {
            $sequence = BinlogCoordinates::sequenceOf($file->name);
            if ($previous !== null && $sequence !== $previous + 1) {
                throw new InvalidArgumentException('Captured binary logs must be contiguous.');
            }
            $previous = $sequence;
        }
    }

    /**
     * @return list<string>
     */
    public function fileNames(): array
    {
        return array_map(static fn (CapturedBinlog $f): string => $f->name, $this->files);
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }
}
