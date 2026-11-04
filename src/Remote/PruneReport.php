<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

/**
 * Result of {@see RemoteBackupPublisher::prune()}.
 */
final readonly class PruneReport
{
    /**
     * @param list<string> $deletedKeys archive keys deleted (their sidecars too)
     * @param array<string, string> $errors key => exception class (the object stays for the next run)
     */
    public function __construct(
        public array $deletedKeys = [],
        public array $errors = [],
    ) {
    }
}
