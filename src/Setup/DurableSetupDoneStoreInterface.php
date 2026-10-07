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

    /**
     * Clears the durable completion signal (v1.15+).
     *
     * Called by {@see SetupWizardReopener} when {@code setup.reopen_when_detector_requires} is enabled and a
     * need detector still reports setup is required although {@see isDone()} was true. Implementations that
     * cannot (or must not) clear should no-op; {@see NullDurableSetupDoneStore} does.
     */
    public function clearDone(): void;
}
