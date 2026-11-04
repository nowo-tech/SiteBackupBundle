<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Storage\RestoreProgressStorageInterface;

final class MemoryRestoreProgressStorage implements RestoreProgressStorageInterface
{
    private RestoreProgress $progress;

    public function __construct()
    {
        $this->progress = new RestoreProgress();
    }

    public function load(): RestoreProgress
    {
        return $this->progress;
    }

    public function save(RestoreProgress $progress): void
    {
        $this->progress = $progress;
    }
}
