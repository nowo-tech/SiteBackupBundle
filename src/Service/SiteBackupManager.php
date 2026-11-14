<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Service;

use Closure;
use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Event\BackupCreatedEvent;
use Nowo\SiteBackupBundle\Event\BackupDeletedEvent;
use Nowo\SiteBackupBundle\Event\BackupFailedEvent;
use Nowo\SiteBackupBundle\Event\BackupImportedEvent;
use Nowo\SiteBackupBundle\Event\BackupStartedEvent;
use Nowo\SiteBackupBundle\Event\RestoreCompletedEvent;
use Nowo\SiteBackupBundle\Event\RestoreFailedEvent;
use Nowo\SiteBackupBundle\Event\RestoreStartedEvent;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\BinlogFilterException;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Incremental\BackupChain;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreReport;
use Nowo\SiteBackupBundle\Incremental\ChainVerifier;
use Nowo\SiteBackupBundle\Incremental\IncrementalBackupManagerInterface;
use Nowo\SiteBackupBundle\Incremental\IncrementalOptions;
use Nowo\SiteBackupBundle\Incremental\RestoreMarker;
use Nowo\SiteBackupBundle\Lock\BackupOperationLock;
use Nowo\SiteBackupBundle\Lock\BackupOperationLockHandle;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\ChainRestorer;
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
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function array_reverse;
use function array_values;
use function bin2hex;
use function count;
use function is_array;
use function is_string;
use function random_bytes;
use function sprintf;

use const DATE_ATOM;

/**
 * Application facade for creating backups and running safe restores (default {@see SiteBackupManagerInterface}).
 *
 * REQ-OBS-001: every destructive operation (create, delete, restore) logs start / success / failure on the
 * `site_backup` channel with the backup id only — no actor, label, paths or credentials; error messages pass
 * through {@see SecretRedactor}.
 */
final class SiteBackupManager implements SiteBackupManagerInterface, IncrementalBackupManagerInterface
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
        private readonly ?ChainRestorer $chainRestorer = null,
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
     * @throws BinlogGapException when an incremental's chain cannot continue (1.36+; a restore closed it, or the logs)
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

            // "New full after restore": checked again under the operation lock (a restore may have run since).
            $parent = $options->parentId !== null ? $this->archiver->find($options->parentId) : null;
            if ($parent instanceof BackupArtifact) {
                try {
                    RestoreMarker::assertOpen($this->archiver->restoreMarkerPath(), $parent->getId(), $parent->getMeta());
                } catch (BinlogGapException $e) {
                    $this->recordNeedsFull($e, $parent->getId(), $actor);

                    throw $e;
                }
            }
            $this->logger->info('SiteBackup: backup started.', $this->context('create') + ['operation_id' => $operationId, 'format' => $options->format->value]);
            $this->eventDispatcher?->dispatch(new BackupStartedEvent($operationId, $options->toLoggableArray(), $actor));

            try {
                $artifact = $this->archiver->create($options);
            } catch (Throwable $e) {
                $this->logger->error('SiteBackup: backup failed.', $this->context('create', null, $e) + ['operation_id' => $operationId]);
                $this->recordNeedsFull($e, $options->parentId, $actor);
                $this->eventDispatcher?->dispatch(new BackupFailedEvent(
                    $operationId,
                    $e::class,
                    SecretRedactor::fromThrowable($e),
                    $options->toLoggableArray(),
                    $actor,
                ));

                throw $e;
            }
            $this->logger->info('SiteBackup: backup created.', $this->context('create', $artifact->getId()) + ['size_bytes' => $artifact->getSizeBytes(), 'operation_id' => $operationId]);
            // EN-I12: skipped secret paths / external links / table-rule warnings (counts and names only) at notice.
            foreach ($artifact->getWarnings() as $warning) {
                $this->logger->notice('SiteBackup: backup warning.', $this->context('create', $artifact->getId()) + ['warning' => $warning]);
            }
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'create',
                occurredAt: $artifact->getCreatedAt(),
                actor: $actor,
                backupId: $artifact->getId(),
                message: $options->label,
                // PP-D3-L2: warnings (names and reasons only) travel with the history row.
                context: ['size_bytes' => $artifact->getSizeBytes(), 'sha256' => $artifact->getArchiveSha256()] + ($artifact->getWarnings() !== [] ? ['warnings' => $artifact->getWarnings()] : []),
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

        $plan   = RetentionPlanner::plan($items, $this->localRetention, $created->getId(), $this->clock->now());
        $chains = false;
        foreach ($items as $item) {
            $chains = $chains || $item->chainId !== null;
        }
        // 1.36+: with chains, newest first (an incremental before the backup it depends on); otherwise the 1.35 order.
        foreach ($chains ? array_reverse($plan) : $plan as $id) {
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
        // 1.36+: a backup an incremental depends on goes after that incremental (delete chains from their head).
        foreach ($this->archiver->listArtifacts() as $other) {
            if (($other->getMeta()['parent_id'] ?? null) === $id) {
                throw new RuntimeException(sprintf('Backup "%s" is the parent of incremental backup "%s": delete the chain from its head.', $id, $other->getId()));
            }
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
     * @param ArchivePassword|null $password 1.29+: needed for an encrypted (zip AES) archive
     * @param bool $allowPlaintext with a password, also accept an archive that is not encrypted
     *
     * @return array{ok: bool, errors: list<string>, checksums: array<string, string>}
     */
    public function verifyBackup(string $id, #[SensitiveParameter] ?ArchivePassword $password = null, bool $allowPlaintext = false): array
    {
        $artifact = $this->archiver->find($id);
        if (!$artifact instanceof BackupArtifact) {
            return ['ok' => false, 'errors' => [sprintf('Backup "%s" not found.', $id)], 'checksums' => []];
        }

        return $this->archiver->verifyIntegrity($artifact, null, $password, $allowPlaintext);
    }

    /**
     * Checks any archive file without storing it (1.29+): see {@see BackupArchiver::verifyFile()}.
     *
     * @param array<string, mixed>|null $sidecar
     */
    public function verifyFile(string $path, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $expectedSha256 = null, ?array $sidecar = null, bool $allowPlaintext = false, ?bool $requireSignature = null): VerifyFileResult
    {
        return $this->archiver->verifyFile($path, $password, $expectedSha256, $sidecar, $allowPlaintext, $requireSignature);
    }

    /**
     * Imports an archive made elsewhere (1.29+; 1.36+ a list imports a chain): decrypts, verifies, drops secret paths and stores it as a plaintext
     * tar.gz in `storage_dir` (0600). Restore it with {@see self::restore()}, then delete it.
     *
     * @param list<string>|string $path 1.36+: a list imports a chain (full first) and returns its head
     *
     * @throws BackupAlreadyRunningException|CorruptArchiveException|WrongPasswordException
     */
    public function importArchive(string|array $path, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $actor = null, ?ImportOptions $options = null): BackupArtifact
    {
        if (is_array($path)) {
            // 1.36+: a chain (full first); the head is returned.
            $chain = $this->importChain(array_values($path), $password, $actor, $options);

            return $chain[count($chain) - 1];
        }

        $lock = $this->operationLock?->acquire();

        try {
            try {
                $artifact = $this->archiver->import($path, $password, $actor, $options);
            } catch (Throwable $e) {
                $this->logger->error('SiteBackup: import failed.', $this->context('import', null, $e));

                throw $e;
            }
            $this->logger->info('SiteBackup: archive imported.', $this->context('import', $artifact->getId()) + ['size_bytes' => $artifact->getSizeBytes()]);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'import',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $artifact->getId(),
                context: ['size_bytes' => $artifact->getSizeBytes(), 'sha256' => $artifact->getArchiveSha256()],
            ));
            $this->eventDispatcher?->dispatch(new BackupImportedEvent($artifact, $actor));

            return $artifact;
        } finally {
            $lock?->release();
        }
    }

    /**
     * A gap or a refused event: the next backup must be a full one. The history row carries the machine-readable
     * reason (`context.reason`) so hosts can count promotions per reason.
     */
    private function recordNeedsFull(Throwable $e, ?string $parentId, ?string $actor): void
    {
        if (!$e instanceof BinlogGapException && !$e instanceof BinlogFilterException) {
            return;
        }
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->historyStorage->append(new BackupHistoryEntry(
            action: 'create_needs_full',
            occurredAt: $this->clock->now(),
            actor: $actor,
            backupId: $parentId,
            message: SecretRedactor::fromThrowable($e),
            context: ['reason' => $e->getReason()],
        ));
    }

    public function incrementalOptions(string $parentId, ?BackupOptions $base = null): BackupOptions
    {
        $parent = $this->archiver->find($parentId);
        if (!$parent instanceof BackupArtifact) {
            throw new InvalidBackupOptionsException(sprintf('Backup "%s" not found.', $parentId));
        }
        foreach ($this->archiver->listArtifacts() as $other) {
            if (($other->getMeta()['parent_id'] ?? null) === $parentId) {
                throw new InvalidBackupOptionsException(sprintf('Backup "%s" already has an incremental ("%s"): continue from the head of the chain.', $parentId, $other->getId()));
            }
        }

        try {
            RestoreMarker::assertOpen($this->archiver->restoreMarkerPath(), $parentId, $parent->getMeta());
        } catch (BinlogGapException $e) {
            $this->recordNeedsFull($e, $parentId, null);

            throw $e;
        }

        return IncrementalOptions::fromParentMeta($parentId, $parent->getMeta(), $base);
    }

    public function resolveChain(string $headId): BackupChain
    {
        $byId = [];
        foreach ($this->archiver->listArtifacts() as $artifact) {
            $byId[$artifact->getId()] = $artifact;
        }

        $artifacts = [];
        $id        = $headId;
        while (true) {
            $artifact = $byId[$id] ?? null;
            if (!$artifact instanceof BackupArtifact) {
                throw new ChainBrokenException([sprintf('Backup "%s" of the chain is missing from storage_dir.', $id)]);
            }
            if (isset($artifacts[$id]) || count($artifacts) > 10_000) {
                throw new ChainBrokenException(['The chain loops.']);
            }
            $artifacts[$id] = $artifact;
            $parent         = $artifact->getMeta()['parent_id'] ?? null;
            if (!is_string($parent) || $parent === '') {
                break;
            }
            $id = $parent;
        }

        return new BackupChain(array_values(array_reverse($artifacts)));
    }

    public function importChain(array $paths, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $actor = null, ?ImportOptions $options = null): array
    {
        if ($paths === []) {
            throw new InvalidBackupOptionsException('Give the full backup and its incrementals, oldest first.');
        }

        $lock     = $this->operationLock?->acquire();
        $imported = [];

        try {
            $link = null;
            foreach ($paths as $path) {
                $artifact   = $this->archiver->import($path, $password, $actor, $options, $link);
                $imported[] = $artifact;
                $from       = $artifact->getMeta()['imported_from'] ?? null;
                // An archive without an id in its MANIFEST cannot be the parent of the next one (the next import refuses).
                $original = is_array($from) && is_string($from['original_id'] ?? null) ? $from['original_id'] : '';
                $link     = ['original_parent_id' => $original, 'parent_id' => $artifact->getId()];
            }
            ChainVerifier::assertValid((new BackupChain($imported))->links);
        } catch (Throwable $e) {
            foreach (array_reverse($imported) as $artifact) {
                $this->archiver->delete($artifact->getId());
            }
            $this->logger->error('SiteBackup: chain import failed.', $this->context('import', null, $e) + ['imported_then_removed' => count($imported)]);

            throw $e;
        } finally {
            $lock?->release();
        }

        foreach ($imported as $artifact) {
            $this->logger->info('SiteBackup: archive imported.', $this->context('import', $artifact->getId()) + ['size_bytes' => $artifact->getSizeBytes()]);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'import',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $artifact->getId(),
                context: ['size_bytes' => $artifact->getSizeBytes(), 'sha256' => $artifact->getArchiveSha256()],
            ));
            $this->eventDispatcher?->dispatch(new BackupImportedEvent($artifact, $actor));
        }

        return $imported;
    }

    public function incrementalOptionsFromArchive(string $parentArchivePath, #[SensitiveParameter] ?ArchivePassword $password = null, ?BackupOptions $base = null): BackupOptions
    {
        try {
            $parent = $this->archiver->readArchiveMeta($parentArchivePath, $password);
        } catch (Throwable $e) {
            throw new InvalidBackupOptionsException('The parent archive cannot be read: ' . SecretRedactor::fromThrowable($e), 0, $e);
        }

        try {
            RestoreMarker::assertOpen($this->archiver->restoreMarkerPath(), $parent['id'], $parent['meta']);
        } catch (BinlogGapException $e) {
            $this->recordNeedsFull($e, $parent['id'], null);

            throw $e;
        }

        return IncrementalOptions::fromParentMeta($parent['id'], $parent['meta'], $base);
    }

    public function restoreChain(string $headId, ?DateTimeImmutable $until = null, ?string $actor = null, #[SensitiveParameter] ?ArchivePassword $password = null, bool $recreateDatabase = false): ChainRestoreReport
    {
        if (!$this->chainRestorer instanceof ChainRestorer) {
            throw new RuntimeException('Chain restores need the bundle\'s ChainRestorer service.');
        }

        $lock = $this->operationLock?->acquire();

        try {
            $chain = $this->resolveChain($headId);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_chain_start',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $headId,
                context: ['chain' => $chain->ids(), 'until' => $until?->format(DATE_ATOM)],
            ));
            $this->logger->warning('SiteBackup: chain restore started.', $this->context('restore_chain', $headId) + ['links' => (string) count($chain->ids())]);
            // Every chain taken so far is closed before anything is touched (the restore goes into the binary log).
            RestoreMarker::write($this->archiver->restoreMarkerPath(), $this->clock->now(), 'chain', $headId);

            try {
                $report = $this->chainRestorer->restore($chain, $until, $password, $lock instanceof BackupOperationLockHandle ? static fn () => $lock->refresh() : null, $recreateDatabase);
            } catch (Throwable $e) {
                // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
                $this->historyStorage->append(new BackupHistoryEntry(
                    action: 'restore_chain_failed',
                    occurredAt: $this->clock->now(),
                    actor: $actor,
                    backupId: $headId,
                    message: SecretRedactor::fromThrowable($e),
                ));
                $this->logger->error('SiteBackup: chain restore failed.', $this->context('restore_chain', $headId, $e));

                throw $e;
            }

            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: $report->complete ? 'restore_chain_complete' : 'restore_chain_incomplete',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $headId,
            ));
            $this->logger->info('SiteBackup: chain restore completed.', $this->context('restore_chain', $headId));

            // Imported (plaintext) archives are removed once restored, head first.
            foreach ($report->complete ? array_reverse($chain->artifacts) : [] as $artifact) {
                if (($artifact->getMeta()['delete_after_restore'] ?? false) === true) {
                    $this->doDelete($artifact->getId(), $actor ?? 'restore');
                }
            }

            return $report;
        } finally {
            $lock?->release();
        }
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
            return $this->doRestore($backupId, $actor, $lock);
        } finally {
            $lock?->release();
        }
    }

    private function doRestore(string $backupId, ?string $actor, ?BackupOperationLockHandle $lock): RestoreProgress
    {
        $artifact = $this->archiver->find($backupId);
        if (!$artifact instanceof BackupArtifact) {
            throw new RuntimeException(sprintf('Backup "%s" not found.', $backupId));
        }
        RestoreOrchestrator::assertNotIncremental($artifact);
        RestoreMarker::write($this->archiver->restoreMarkerPath(), $this->clock->now(), 'restore', $backupId);

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
            // EN-L4: a long restore keeps the operation lock alive (TTL stores).
            $progress = $this->restoreOrchestrator->restore($artifact, $actor, $lock instanceof BackupOperationLockHandle ? static fn () => $lock->refresh() : null);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_complete',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $backupId,
            ));
            $this->eventDispatcher?->dispatch(new RestoreCompletedEvent($artifact, $progress, $actor));
            $this->logger->info('SiteBackup: restore completed.', $this->context('restore', $backupId));

            // EO-L6 / Q-O6: an imported (plaintext) archive is removed once it has been restored.
            if (($artifact->getMeta()['delete_after_restore'] ?? false) === true) {
                $this->doDelete($backupId, $actor ?? 'restore');
            }

            return $progress;
        } catch (Throwable $e) {
            // 1.34+: the history file and the event get the redacted, capped message only (a PDO / client error may
            // quote the DSN or a password); the raw throwable stays in memory on the event and is rethrown.
            $error = SecretRedactor::fromThrowable($e);
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->historyStorage->append(new BackupHistoryEntry(
                action: 'restore_failed',
                occurredAt: $this->clock->now(),
                actor: $actor,
                backupId: $backupId,
                message: $error,
            ));
            $this->eventDispatcher?->dispatch(new RestoreFailedEvent($artifact, $error, $actor, $e));
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
