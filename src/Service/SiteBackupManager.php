<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Service;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\BackupFailedEvent;
use Nowo\SiteBackupBundle\Event\BackupStartedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Lock\BackupOperationLockHandle;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Retention\RetentionItem;
use Nowo\SiteBackupBundle\Retention\RetentionPlanner;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Storage\BackupHistoryStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function bin2hex;
use function mb_substr;
use function random_bytes;
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
        private readonly ?BackupOperationLock $operationLock = null,
        private readonly ?RetentionPolicy $localRetention = null,
        private readonly ArchiveFormat $defaultFormat = ArchiveFormat::TarGz,
        private readonly int $defaultCompressionLevel = 6,
    ) {
    }

    /**
     * Options of a backup created without explicit options (panel, CLI, the 1.27 signature): configured paths and
     * dump, `backup.default_format`, `backup.compression_level`.
     */
    public function defaultOptions(?string $label = null, ?string $createdBy = null): BackupOptions
    {
        return new BackupOptions(
            includeFiles: $this->defaultFormat !== ArchiveFormat::SqlGz,
            format: $this->defaultFormat,
            compressionLevel: $this->defaultCompressionLevel,
            label: $label,
            createdBy: $createdBy,
        );
    }

    /**
     * Creates a backup in `storage_dir`.
     *
     * 1.28+: pass {@see BackupOptions}; the 1.27 call `createBackup($label, $createdBy)` keeps working. No HTTP,
     * session or security access: safe from a controller, a console command or a Messenger handler.
     *
     * @throws BackupAlreadyRunningException when another backup / restore holds the operation lock
     */
    public function createBackup(BackupOptions|string|null $options = null, ?string $createdBy = null): BackupArtifact
    {
        if (!$options instanceof BackupOptions) {
            $options = $this->defaultOptions($options, $createdBy);
        }
        $actor       = $options->createdBy;
        $operationId = bin2hex(random_bytes(8));

        $lock = $this->operationLock?->acquire();

        try {
            if ($lock instanceof BackupOperationLockHandle) {
                $inner = $options->heartbeat;
                // @igor-ignore - Per-call options; the closure lives only for this backup and is never stored on the service.
                $options = $options->withHeartbeat(static function (BackupProgress $progress) use ($lock, $inner): void {
                    $lock->refresh();
                    if ($inner !== null) {
                        $inner($progress);
                    }
                });
            }

            $this->logger->info('SiteBackup: backup started.', $this->context('create') + ['operation_id' => $operationId, 'format' => $options->format->value]);
            $this->eventDispatcher?->dispatch(new BackupStartedEvent($operationId, $options->toLoggableArray(), $actor));

            try {
                $artifact = $this->archiver->create($options);
            } catch (Throwable $e) {
                $this->logger->error('SiteBackup: backup failed.', $this->context('create', null, $e) + ['operation_id' => $operationId]);
                $this->eventDispatcher?->dispatch(new BackupFailedEvent(
                    $operationId,
                    $e::class,
                    mb_substr(SecretRedactor::redact($e->getMessage()), 0, 500),
                    $options->toLoggableArray(),
                    $actor,
                ));

                throw $e;
            }
            $this->logger->info('SiteBackup: backup created.', $this->context('create', $artifact->getId()) + ['size_bytes' => $artifact->getSizeBytes(), 'operation_id' => $operationId]);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'create',
                occurredAt: $artifact->getCreatedAt(),
                actor: $actor,
                backupId: $artifact->getId(),
                message: $options->label,
                context: ['size_bytes' => $artifact->getSizeBytes(), 'sha256' => $artifact->getArchiveSha256()],
            ));
            $this->eventDispatcher?->dispatch(new BackupCreatedEvent($artifact, $actor, $operationId));

            $this->applyLocalRetention($artifact, $actor);

            return $artifact;
        } finally {
            $lock?->release();
        }
    }

    /**
     * `backup.retention` for `storage_dir` (1.28+): after a successful local backup, delete what the policy drops.
     * A failed delete is logged and left for the next run.
     */
    private function applyLocalRetention(BackupArtifact $created, ?string $actor): void
    {
        if (!$this->localRetention instanceof RetentionPolicy || $this->localRetention->isEmpty()) {
            return;
        }

        $items = [];
        foreach ($this->archiver->listArtifacts() as $artifact) {
            $items[] = RetentionItem::fromArtifact($artifact);
        }

        foreach (RetentionPlanner::plan($items, $this->localRetention, $created->getId(), $this->clock->now()) as $id) {
            try {
                $this->doDelete($id, $actor ?? 'retention');
            } catch (Throwable $e) {
                $this->logger->warning('SiteBackup: retention delete failed.', $this->context('retention', $id, $e));
            }
        }
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

    /**
     * @throws BackupAlreadyRunningException when another backup / restore holds the operation lock
     */
    public function deleteBackup(string $id, ?string $actor = null): void
    {
        $lock = $this->operationLock?->acquire();

        try {
            $this->doDelete($id, $actor);
        } finally {
            $lock?->release();
        }
    }

    private function doDelete(string $id, ?string $actor): void
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

    /**
     * @throws BackupAlreadyRunningException when a backup holds the operation lock (1.28+)
     */
    public function restore(string $backupId, ?string $actor = null): RestoreProgress
    {
        $lock = $this->operationLock?->acquire();

        try {
            return $this->doRestore($backupId, $actor);
        } finally {
            $lock?->release();
        }
    }

    private function doRestore(string $backupId, ?string $actor): RestoreProgress
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
