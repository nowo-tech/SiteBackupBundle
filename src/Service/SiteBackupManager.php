<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Service;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Storage\BackupHistoryStorageInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Application facade for creating backups and running safe restores.
 */
final class SiteBackupManager
{
    public function __construct(
        private readonly BackupArchiver $archiver,
        private readonly RestoreOrchestrator $restoreOrchestrator,
        private readonly BackupHistoryStorageInterface $historyStorage,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function createBackup(?string $label = null, ?string $createdBy = null): BackupArtifact
    {
        $artifact = $this->archiver->create($label, $createdBy);
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
            throw new RuntimeException(sprintf('Unable to delete backup "%s".', $id));
        }
        $this->historyStorage->append(new BackupHistoryEntry(
            action: 'delete',
            occurredAt: new DateTimeImmutable(),
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

        $this->historyStorage->append(new BackupHistoryEntry(
            action: 'restore_start',
            occurredAt: new DateTimeImmutable(),
            actor: $actor,
            backupId: $backupId,
        ));
        $this->eventDispatcher?->dispatch(new RestoreStartedEvent($artifact, $actor));

        try {
            $progress = $this->restoreOrchestrator->restore($artifact, $actor);
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_complete',
                occurredAt: new DateTimeImmutable(),
                actor: $actor,
                backupId: $backupId,
            ));
            $this->eventDispatcher?->dispatch(new RestoreCompletedEvent($artifact, $progress, $actor));

            return $progress;
        } catch (Throwable $e) {
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_failed',
                occurredAt: new DateTimeImmutable(),
                actor: $actor,
                backupId: $backupId,
                message: $e->getMessage(),
            ));
            $this->eventDispatcher?->dispatch(new RestoreFailedEvent($artifact, $e->getMessage(), $actor));
            throw $e;
        }
    }

    public function clearRestoreStatus(): void
    {
        $this->restoreOrchestrator->clearFailedOrCompleted();
    }

    /**
     * @return list<BackupHistoryEntry>
     */
    public function history(int $limit = 50): array
    {
        return $this->historyStorage->list($limit);
    }
}
