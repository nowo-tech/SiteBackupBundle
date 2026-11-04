<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Storage\BackupHistoryStorageInterface;

use function array_reverse;
use function array_slice;

final class MemoryHistoryStorage implements BackupHistoryStorageInterface
{
    /** @var list<BackupHistoryEntry> */
    public array $entries = [];

    public function append(BackupHistoryEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function list(int $limit = 50): array
    {
        return array_slice(array_reverse($this->entries), 0, $limit);
    }
}
