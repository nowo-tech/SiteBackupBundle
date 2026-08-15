<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Throwable;

/**
 * Writes to cache always; mirrors to Doctrine when the connection works.
 * Loads Doctrine first (survives cache flush), then falls back to cache.
 *
 * Unlike {@see ChainSetupProgressStorage}, never writes JSON under {@code var/}.
 */
final class CacheDoctrineSetupProgressStorage implements SetupProgressStorageInterface
{
    public function __construct(
        private readonly CacheSetupProgressStorage $cache,
        private readonly SetupProgressStorageInterface $doctrine,
        private readonly ?SchemaExistenceCheckerInterface $schemaChecker = null,
    ) {
    }

    public function load(): SetupProgress
    {
        if ($this->doctrineLooksUsable()) {
            try {
                $fromDb = $this->doctrine->load();
                if ($this->hasMeaningfulProgress($fromDb)) {
                    $this->cache->save($fromDb);

                    return $fromDb;
                }
            } catch (Throwable) {
                // Fall through to cache.
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
        } catch (Throwable) {
            // Cache remains source of truth until database_create / DBAL works.
        }
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
        return SetupProgress::PHASE_IDLE !== $progress->getPhase()
            || $progress->getStartedAt() instanceof DateTimeImmutable
            || [] !== $progress->getCompletedStepIds();
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
        if (null !== $progress->getCurrentStepId()) {
            $ids[] = $progress->getCurrentStepId();
        }

        foreach ($ids as $id) {
            if (1 === preg_match('/migration|messenger|seed|admin_user|sample_data|sql_file|full_database/i', $id)) {
                return true;
            }
        }

        return false;
    }
}
