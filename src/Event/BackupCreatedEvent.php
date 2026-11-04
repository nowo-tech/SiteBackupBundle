<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Symfony\Contracts\EventDispatcher\Event;

final class BackupCreatedEvent extends Event
{
    public function __construct(
        private readonly BackupArtifact $artifact,
        private readonly ?string $actor = null,
        private readonly ?string $operationId = null,
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
     * Same id as the {@see BackupStartedEvent} of this run (1.28+); null when built by older code.
     */
    public function getOperationId(): ?string
    {
        return $this->operationId;
    }

    public function getFormat(): ArchiveFormat
    {
        return $this->artifact->getFormat();
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->artifact->getWarnings();
    }
}
