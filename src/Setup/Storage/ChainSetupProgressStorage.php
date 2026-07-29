<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Throwable;

/**
 * Writes to filesystem always; also mirrors to Doctrine when the connection works.
 * Loads Doctrine first (survives var/ wipe), then falls back to the JSON file.
 */
final class ChainSetupProgressStorage implements SetupProgressStorageInterface
{
    public function __construct(
        private readonly SetupProgressStorageInterface $filesystem,
        private readonly DoctrineDbalSetupProgressStorage $doctrine,
    ) {
    }

    public function load(): SetupProgress
    {
        if ($this->doctrine->isUsable()) {
            try {
                $fromDb = $this->doctrine->load();
                if ($fromDb->getPhase() !== SetupProgress::PHASE_IDLE || $fromDb->getStartedAt() instanceof DateTimeImmutable || $fromDb->getCompletedStepIds() !== []) {
                    return $fromDb;
                }
            } catch (Throwable) {
                // fall through to filesystem
            }
        }

        return $this->filesystem->load();
    }

    public function save(SetupProgress $progress): void
    {
        $this->filesystem->save($progress);

        if (!$this->doctrine->isUsable()) {
            return;
        }

        try {
            $this->doctrine->save($progress);
        } catch (Throwable) {
            // Filesystem remains source of truth when DB is cold / table missing mid-migrate.
        }
    }
}
