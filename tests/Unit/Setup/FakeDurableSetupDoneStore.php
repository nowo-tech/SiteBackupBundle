<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;

final class FakeDurableSetupDoneStore implements DurableSetupDoneStoreInterface
{
    public int $clearCalls = 0;

    public function __construct(private bool $done)
    {
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function markDone(): void
    {
        $this->done = true;
    }

    public function clearDone(): void
    {
        $this->done = false;
        ++$this->clearCalls;
    }
}
