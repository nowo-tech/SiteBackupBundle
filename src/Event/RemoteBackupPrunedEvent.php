<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Remote\PruneReport;
use Nowo\SiteBackupBundle\Remote\RemoteBackupPublisher;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by {@see RemoteBackupPublisher::prune()} (1.29+).
 */
final class RemoteBackupPrunedEvent extends Event
{
    public function __construct(
        private readonly string $prefix,
        private readonly PruneReport $report,
    ) {
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getReport(): PruneReport
    {
        return $this->report;
    }
}
