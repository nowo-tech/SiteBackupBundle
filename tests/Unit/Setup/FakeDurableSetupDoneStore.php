<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;

final readonly class FakeDurableSetupDoneStore implements DurableSetupDoneStoreInterface
{
    public function __construct(private bool $done)
    {
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function markDone(): void
    {
    }
}
