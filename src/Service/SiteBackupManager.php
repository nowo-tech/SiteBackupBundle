<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Service;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Storage\BackupHistoryStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function sprintf;

/**
 * Application facade for creating backups and running safe restores.
 *
 * REQ-OBS-001: every destructive operation (create, delete, restore) logs start / success / failure on the
 * `site_backup` channel with the backup id only — no actor, label, paths or credentials; error messages pass
 * through {@see SecretRedactor}.
 */
final class SiteBackupManager
{
    public function __construct(
        private readonly BackupArchiver $archiver,
        private readonly RestoreOrchestrator $restoreOrchestrator,
        private readonly BackupHistoryStorageInterface $historyStorage,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function createBackup(?string $label = null, ?string $createdBy = null): BackupArtifact
    {
        $this->logger->info('SiteBackup: backup started.', $this->context('create'));

        try {
            $artifact = $this->archiver->create($label, $createdBy);
        } catch (Throwable $e) {
            $this->logger->error('SiteBackup: backup failed.', $this->context('create', null, $e));

            throw $e;
        }
        $this->logger->info('SiteBackup: backup created.', $this->context('create', $artifact->getId()) + ['size_bytes' => $artifact->getSizeBytes()]);
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->historyStorage->append(new BackupHistoryEntry(
            action: 'create',
            occurredAt: $artifact->getCreatedAt(),
            actor: $createdBy,
            backupId: $artifact->getId(),
            message: $label,
            context: ['size_bytes' => $artifact->getSizeBytes(), 'sha256' => $artifact->getArchiveSha256()],
        ));
        $this->eventDispatcher?->dispatch(new BackupCreatedEvent($artifact, $createdBy));

        return $artifact;
    }

    /**
     * @return list<BackupArtifact>
     */
    public function listBackups(): array
    {
        return $this->archiver->listArtifacts();
    }

    public function getBackup(string $id): ?BackupArtifact
    {
        return $this->archiver->find($id);
    }

    public function deleteBackup(string $id, ?string $actor = null): void
    {
        $artifact = $this->archiver->find($id);
        if (!$artifact instanceof BackupArtifact) {
            throw new RuntimeException(sprintf('Backup "%s" not found.', $id));
        }
        if (!$this->archiver->delete($id)) {
            $this->logger->error('SiteBackup: backup deletion failed.', $this->context('delete', $id));

            throw new RuntimeException(sprintf('Unable to delete backup "%s".', $id));
        }
        $this->logger->warning('SiteBackup: backup deleted.', $this->context('delete', $id));
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->historyStorage->append(new BackupHistoryEntry(
            action: 'delete',
            occurredAt: $this->clock->now(),
            actor: $actor,
            backupId: $id,
        ));
        $this->eventDispatcher?->dispatch(new BackupDeletedEvent($artifact, $actor));
    }

    /**
     * @return array{ok: bool, errors: list<string>, checksums: array<string, string>}
     */
    public function verifyBackup(string $id): array
    {
        $artifact = $this->archiver->find($id);
        if (!$artifact instanceof BackupArtifact) {
            return ['ok' => false, 'errors' => [sprintf('Backup "%s" not found.', $id)], 'checksums' => []];
        }

        return $this->archiver->verifyIntegrity($artifact);
    }

    public function getRestoreProgress(): RestoreProgress
    {
        return $this->restoreOrchestrator->getProgress();
    }

    public function isRestoreActive(): bool
    {
        return $this->restoreOrchestrator->isRestoreActive();
    }

    public function restore(string $backupId, ?string $actor = null): RestoreProgress
    {
        $artifact = $this->archiver->find($backupId);
        if (!$artifact instanceof BackupArtifact) {
            throw new RuntimeException(sprintf('Backup "%s" not found.', $backupId));
        }

        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->historyStorage->append(new BackupHistoryEntry(
            action: 'restore_start',
            occurredAt: $this->clock->now(),
            actor: $actor,
            backupId: $backupId,
        ));
        $this->eventDispatcher?->dispatch(new RestoreStartedEvent($artifact, $actor));
        $this->logger->warning('SiteBackup: restore started.', $this->context('restore', $backupId));

        try {
            $progress = $this->restoreOrchestrator->restore($artifact, $actor);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_complete',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $backupId,
            ));
            $this->eventDispatcher?->dispatch(new RestoreCompletedEvent($artifact, $progress, $actor));
            $this->logger->info('SiteBackup: restore completed.', $this->context('restore', $backupId));

            return $progress;
        } catch (Throwable $e) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_failed',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $backupId,
                message: $e->getMessage(),
            ));
            $this->eventDispatcher?->dispatch(new RestoreFailedEvent($artifact, $e->getMessage(), $actor));
            $this->logger->error('SiteBackup: restore failed.', $this->context('restore', $backupId, $e));

            throw $e;
        }
    }

    public function clearRestoreStatus(): void
    {
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->restoreOrchestrator->clearFailedOrCompleted();
    }

    /**
     * @return list<BackupHistoryEntry>
     */
    public function history(int $limit = 50): array
    {
        return $this->historyStorage->list($limit);
    }

    /**
     * Structured log context (no actor / label / secrets).
     *
     * @return array<string, string>
     */
    private function context(string $action, ?string $backupId = null, ?Throwable $error = null): array
    {
        $context = ['bundle' => 'site_backup', 'action' => $action];
        if ($backupId !== null) {
            $context['backup_id'] = $backupId;
        }
        if ($error instanceof Throwable) {
            $context['exception'] = $error::class;
            $context['error']     = SecretRedactor::redact($error->getMessage());
        }

        return $context;
    }
}
