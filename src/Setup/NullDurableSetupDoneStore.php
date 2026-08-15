<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * Default no-op store: setup is never marked done in a durable store (BC).
 */
final readonly class NullDurableSetupDoneStore implements DurableSetupDoneStoreInterface
{
    public function isDone(): bool
    {
        return false;
    }

    public function markDone(): void
    {
    }
}
