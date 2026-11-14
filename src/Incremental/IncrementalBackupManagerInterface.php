<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use SensitiveParameter;

/**
 * Backup chains (1.36+, port): a full backup with binlog coordinates and / or a file manifest, then incrementals.
 * Implemented by {@see SiteBackupManager} next to its 1.x interface (which is left
 * unchanged so existing implementations keep working).
 */
interface IncrementalBackupManagerInterface
{
    /**
     * Options of the next incremental after $parentId (the last backup of its chain); create it with
     * `createBackup($options)`.
     *
     * @throws InvalidBackupOptionsException when the parent cannot be continued
     */
    public function incrementalOptions(string $parentId, ?BackupOptions $base = null): BackupOptions;

    /**
     * The chain that ends with $headId, full backup first (walks `parent_id` in `storage_dir`).
     *
     * @throws ChainBrokenException when a parent is missing or a link is not part of a chain
     */
    public function resolveChain(string $headId): BackupChain;

    /**
     * Imports a whole chain made elsewhere (full first, then every incremental in order) and checks it; on any problem
     * every archive imported by this call is removed again.
     *
     * @param list<string> $paths
     *
     * @return list<BackupArtifact> the imported chain, full first
     */
    public function importChain(array $paths, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $actor = null, ?ImportOptions $options = null): array;

    /**
     * Restores the chain ending with $headId, up to $until (inclusive, UTC; null = the head). A target schema that
     * holds objects the full backup does not re-create is refused unless $recreateDatabase (drop + create first).
     *
     * @throws BackupAlreadyRunningException|ChainBrokenException|ChainRestoreException
     */
    public function restoreChain(string $headId, ?DateTimeImmutable $until = null, ?string $actor = null, #[SensitiveParameter] ?ArchivePassword $password = null, bool $recreateDatabase = false): ChainRestoreReport;

    /**
     * Options of the next incremental when the parent is only available as an archive (its remote sidecar carries no
     * file manifest): the archive is verified and its MANIFEST meta read; nothing is stored.
     *
     * @throws InvalidBackupOptionsException when the parent cannot be continued
     */
    public function incrementalOptionsFromArchive(string $parentArchivePath, #[SensitiveParameter] ?ArchivePassword $password = null, ?BackupOptions $base = null): BackupOptions;
}
