<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use Nowo\SiteBackupBundle\Model\SetupProgress;

interface SetupProgressStorageInterface
{
    public function load(): SetupProgress;

    public function save(SetupProgress $progress): void;
}
