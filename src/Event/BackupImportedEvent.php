<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Symfony\Contracts\EventDispatcher\Event;

use function is_array;
use function is_string;

/**
 * An archive made elsewhere was imported into `storage_dir` (1.29+). The stored copy is a plaintext tar.gz.
 */
final class BackupImportedEvent extends Event
{
    public function __construct(
        private readonly BackupArtifact $artifact,
        private readonly ?string $actor = null,
    ) {
    }

    public function getArtifact(): BackupArtifact
    {
        return $this->artifact;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    /**
     * Format of the source archive (zip, tar.gz, sql.gz).
     */
    public function getSourceFormat(): ?string
    {
        $from = $this->artifact->getMeta()['imported_from'] ?? null;

        return is_array($from) && is_string($from['format'] ?? null) ? $from['format'] : null;
    }

    public function wasEncrypted(): bool
    {
        $from = $this->artifact->getMeta()['imported_from'] ?? null;

        return is_array($from) && ($from['encrypted'] ?? false) === true;
    }
}
