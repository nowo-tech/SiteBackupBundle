<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Symfony\Contracts\EventDispatcher\Event;

final class RestoreCompletedEvent extends Event
{
    public function __construct(
        private readonly BackupArtifact $artifact,
        private readonly RestoreProgress $progress,
        private readonly ?string $actor = null,
    ) {
    }

    public function getArtifact(): BackupArtifact
    {
        return $this->artifact;
    }

    public function getProgress(): RestoreProgress
    {
        return $this->progress;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }
}
