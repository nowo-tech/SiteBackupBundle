<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Symfony\Contracts\EventDispatcher\Event;

final class RestoreFailedEvent extends Event
{
    public function __construct(
        private readonly BackupArtifact $artifact,
        private readonly string $error,
        private readonly ?string $actor = null,
    ) {
    }

    public function getArtifact(): BackupArtifact
    {
        return $this->artifact;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }
}
