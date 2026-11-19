<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Writes to cache always; mirrors to Doctrine when the connection works.
 * Loads Doctrine first (survives cache flush), then falls back to cache.
 *
 * Unlike {@see ChainSetupProgressStorage}, never writes JSON under {@code var/}.
 *
 * 1.37+: a database failure is logged once per request (warning, no exception message) through
 * {@see SetupProgressFallbackWarning} instead of being swallowed silently. The Doctrine layer is still tried on
 * every call: the database often appears in the middle of a wizard request (database_create step).
 */
final class CacheDoctrineSetupProgressStorage implements SetupProgressStorageInterface, ResetInterface
{
    private readonly SetupProgressFallbackWarning $warning;

    public function __construct(
        private readonly CacheSetupProgressStorage $cache,
        private readonly SetupProgressStorageInterface $doctrine,
        private readonly ?SchemaExistenceCheckerInterface $schemaChecker = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->warning = new SetupProgressFallbackWarning($logger, 'cache_doctrine', 'cache');
    }

    public function load(): SetupProgress
    {
        if ($this->doctrineLooksUsable()) {
            try {
                $fromDb = $this->doctrine instanceof DoctrineDbalSetupProgressStorage
                    ? $this->doctrine->loadOrFail()
                    : $this->doctrine->load();
            } catch (Throwable $e) {
                $this->warning->report('load', $e);
                $fromDb = null;
            }

            if ($fromDb instanceof SetupProgress && $this->hasMeaningfulProgress($fromDb)) {
                $this->cache->save($fromDb);

                return $fromDb;
            }
        }

        $fromCache = $this->cache->load();
        if ($this->isStaleAfterSchemaLoss($fromCache)) {
            $this->cache->save(new SetupProgress());

            return new SetupProgress();
        }

        return $fromCache;
    }

    public function save(SetupProgress $progress): void
    {
        $this->cache->save($progress);

        if (!$this->doctrineLooksUsable()) {
            return;
        }

        try {
            $this->doctrine->save($progress);
        } catch (Throwable $e) {
            // Cache remains source of truth until database_create / DBAL works.
            $this->warning->report('save', $e);
        }
    }

    public function reset(): void
    {
        $this->warning->reset();
    }

    private function doctrineLooksUsable(): bool
    {
        if ($this->doctrine instanceof DoctrineDbalSetupProgressStorage) {
            return $this->doctrine->isUsable();
        }

        return true;
    }

    private function hasMeaningfulProgress(SetupProgress $progress): bool
    {
        return $progress->getPhase() !== SetupProgress::PHASE_IDLE
            || $progress->getStartedAt() instanceof DateTimeImmutable
            || $progress->getCompletedStepIds() !== [];
    }

    /**
     * Cache claimed migrations/seed/… but app tables are gone (operator dropped the DB).
     */
    private function isStaleAfterSchemaLoss(SetupProgress $progress): bool
    {
        if (!$this->hasMeaningfulProgress($progress)) {
            return false;
        }

        if (!$this->schemaChecker instanceof SchemaExistenceCheckerInterface || $this->schemaChecker->schemaExists()) {
            return false;
        }

        $ids = $progress->getCompletedStepIds();
        if ($progress->getCurrentStepId() !== null) {
            $ids[] = $progress->getCurrentStepId();
        }

        foreach ($ids as $id) {
            if (preg_match('/migration|messenger|seed|admin_user|sample_data|sql_file|full_database/i', $id) === 1) {
                return true;
            }
        }

        return false;
    }
}
