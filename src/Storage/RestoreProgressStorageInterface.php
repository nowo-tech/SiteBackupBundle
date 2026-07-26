<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Storage;

use Nowo\SiteBackupBundle\Model\RestoreProgress;

interface RestoreProgressStorageInterface
{
    public function load(): RestoreProgress;

    public function save(RestoreProgress $progress): void;
}
