<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Writes to filesystem always; also mirrors to Doctrine when the connection works.
 * Loads Doctrine first (survives var/ wipe), then falls back to the JSON file.
 *
 * Cold-start: early wizard steps often have no DB yet — filesystem remains the
 * source of truth until DBAL is usable; Doctrine writes (singleton + optional
 * step journal) are best-effort and must not require Symfony Migrations.
 *
 * 1.37+: a database failure is logged once per request (warning, no exception message) through
 * {@see SetupProgressFallbackWarning} instead of being swallowed silently. The Doctrine layer is still tried on
 * every call: the database often appears in the middle of a wizard request (database_create step).
 */
final class ChainSetupProgressStorage implements SetupProgressStorageInterface, ResetInterface
{
    private readonly SetupProgressFallbackWarning $warning;

    public function __construct(
        private readonly SetupProgressStorageInterface $filesystem,
        private readonly DoctrineDbalSetupProgressStorage $doctrine,
        ?LoggerInterface $logger = null,
    ) {
        $this->warning = new SetupProgressFallbackWarning($logger, 'chain', 'filesystem');
    }

    public function load(): SetupProgress
    {
        if ($this->doctrine->isUsable()) {
            try {
                $fromDb = $this->doctrine->loadOrFail();
                if ($fromDb->getPhase() !== SetupProgress::PHASE_IDLE || $fromDb->getStartedAt() instanceof DateTimeImmutable || $fromDb->getCompletedStepIds() !== []) {
                    return $fromDb;
                }
            } catch (Throwable $e) {
                $this->warning->report('load', $e);
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
        } catch (Throwable $e) {
            // Filesystem remains source of truth when DB is cold / table missing mid-migrate.
            $this->warning->report('save', $e);
        }
    }

    public function reset(): void
    {
        $this->warning->reset();
    }
}
