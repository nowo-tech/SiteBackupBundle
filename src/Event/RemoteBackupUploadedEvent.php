<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Nowo\SiteBackupBundle\Remote\RemoteObject;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by {@see RemoteBackupPublisher::publish()} after a verified upload (1.29+).
 */
final class RemoteBackupUploadedEvent extends Event
{
    public function __construct(
        private readonly BackupArtifact $artifact,
        private readonly RemoteObject $object,
    ) {
    }

    public function getArtifact(): BackupArtifact
    {
        return $this->artifact;
    }

    public function getObject(): RemoteObject
    {
        return $this->object;
    }
}
