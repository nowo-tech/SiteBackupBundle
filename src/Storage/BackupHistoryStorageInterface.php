<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Storage;

use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;

interface BackupHistoryStorageInterface
{
    public function append(BackupHistoryEntry $entry): void;

    /**
     * @return list<BackupHistoryEntry>
     */
    public function list(int $limit = 50): array;
}
