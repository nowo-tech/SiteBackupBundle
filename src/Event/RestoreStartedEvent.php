<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Symfony\Contracts\EventDispatcher\Event;

final class RestoreStartedEvent extends Event
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
}
