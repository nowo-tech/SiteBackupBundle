<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;

use function preg_match;

/**
 * One binary log file copied verbatim from the server by {@see BinlogCaptureInterface::capture()} (1.36+).
 */
final readonly class CapturedBinlog
{
    /** Name of the filtered events file of a capture. */
    public const EVENTS = 'events.sql';

    public function __construct(
        public string $name,
        public string $path,
        public int $sizeBytes,
        public string $sha256,
    ) {
        if (preg_match(BinlogCoordinates::FILE_PATTERN, $name) !== 1 && $name !== self::EVENTS) {
            throw new InvalidArgumentException('Binary log file names must look like "<basename>.<number>".');
        }
        if ($sizeBytes < 0 || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('A captured binary log needs a size and a SHA-256.');
        }
    }
}
