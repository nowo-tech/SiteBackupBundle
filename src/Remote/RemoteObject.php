<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

use DateTimeImmutable;

/**
 * One stored object of a {@see BackupRemoteTargetInterface}.
 */
final readonly class RemoteObject
{
    /**
     * @param array<string, string> $metadata native object metadata (empty when the target has none)
     */
    public function __construct(
        public string $key,
        public int $sizeBytes,
        public DateTimeImmutable $lastModified,
        public array $metadata = [],
        public ?string $etag = null,
    ) {
    }
}
