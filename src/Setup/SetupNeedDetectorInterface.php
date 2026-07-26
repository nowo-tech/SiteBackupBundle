<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

interface SetupNeedDetectorInterface
{
    public function isSetupRequired(): bool;

    public function getReason(): string;
}
