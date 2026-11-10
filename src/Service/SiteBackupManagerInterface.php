<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Service;

use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Event\SiteBackupEvents;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use SensitiveParameter;

/**
 * Application API of the bundle: create, list, verify, import, delete and restore backups (1.33+).
 *
 * Type-hint this interface (autowired to {@see SiteBackupManager}) in host code and double it in tests. Writes append
 * history and dispatch the events of {@see SiteBackupEvents}.
 */
interface SiteBackupManagerInterface
{
    /**
     * Options of a backup created without explicit options: configured paths and dump, `backup.default_format`,
     * `backup.compression_level`.
     */
    public function defaultOptions(?string $label = null, ?string $createdBy = null): BackupOptions;

    /**
     * Creates a backup in `storage_dir`; the 1.27 call `createBackup($label, $createdBy)` keeps working.
     *
     * @throws BackupAlreadyRunningException when another backup / restore holds the operation lock
     */
    public function createBackup(BackupOptions|string|null $options = null, ?string $createdBy = null): BackupArtifact;

    /**
     * @return list<BackupArtifact>
     */
    public function listBackups(): array;

    public function getBackup(string $id): ?BackupArtifact;

    /**
     * @throws BackupAlreadyRunningException when another backup / restore holds the operation lock
     */
    public function deleteBackup(string $id, ?string $actor = null): void;

    /**
     * @return array{ok: bool, errors: list<string>, checksums: array<string, string>}
     */
    public function verifyBackup(string $id, #[SensitiveParameter] ?ArchivePassword $password = null, bool $allowPlaintext = false): array;

    /**
     * @param array<string, mixed>|null $sidecar
     */
    public function verifyFile(string $path, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $expectedSha256 = null, ?array $sidecar = null, bool $allowPlaintext = false, ?bool $requireSignature = null): VerifyFileResult;

    /**
     * @throws BackupAlreadyRunningException|CorruptArchiveException|WrongPasswordException
     */
    public function importArchive(string $path, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $actor = null, ?ImportOptions $options = null): BackupArtifact;

    public function getRestoreProgress(): RestoreProgress;

    public function isRestoreActive(): bool;

    /**
     * @throws BackupAlreadyRunningException when a backup holds the operation lock
     */
    public function restore(string $backupId, ?string $actor = null): RestoreProgress;

    public function clearRestoreStatus(): void;

    /**
     * @return list<BackupHistoryEntry>
     */
    public function history(int $limit = 50): array;
}
