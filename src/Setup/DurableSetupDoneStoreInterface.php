<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * Host-provided durable signal that setup completed (survives var/ wipe / container recreate).
 *
 * Default alias points to {@see NullDurableSetupDoneStore} for BC. Replace the alias in the
 * host container when persisting completion in the database (e.g. instance settings).
 */
interface DurableSetupDoneStoreInterface
{
    public function isDone(): bool;

    public function markDone(): void;
}
