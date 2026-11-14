<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Builds the options of the next incremental backup from its parent's meta (1.36+, pure).
 *
 * The parent is the last backup of the chain: its end coordinates become `binlogFrom`, its `file_manifest` becomes
 * `baseManifest` (checked against `file_manifest_sha256`), its include paths are reused (another file set would show
 * every other file as deleted). Format, password, label and the other per-call settings come from `$base`.
 *
 * The parent meta is the local sidecar meta ({@see BackupArtifact::getMeta()}) or, when
 * the local copy is gone, the `chain` block of the remote `.backup.json` sidecar.
 */
final class IncrementalOptions
{
    /** Meta keys a next incremental needs (also the `chain` block of a remote sidecar). */
    public const PARENT_META_KEYS = [
        'type', 'chain_id', 'parent_id', 'includes', 'database_dump', 'binlog_file', 'binlog_position', 'server_uuid',
        'gtid_executed', 'binlog_database', 'binlog_from', 'binlog_files', 'first_event_at', 'last_event_at',
        'binlog_captured_at', 'file_manifest', 'file_manifest_sha256', 'base_manifest_sha256',
    ];

    /**
     * @param array<mixed> $parentMeta
     *
     * @throws InvalidBackupOptionsException when the parent cannot be continued
     */
    public static function fromParentMeta(string $parentId, array $parentMeta, ?BackupOptions $base = null): BackupOptions
    {
        $type    = $parentMeta['type'] ?? null;
        $chainId = $parentMeta['chain_id'] ?? null;
        if (($type !== ChainLink::TYPE_FULL && $type !== ChainLink::TYPE_INCREMENTAL) || !is_string($chainId) || $chainId === '') {
            throw new InvalidBackupOptionsException(sprintf('Backup %s is not part of a chain: create a full backup with recordBinlogCoordinates and / or recordFileManifest first.', $parentId));
        }

        $from = BinlogCoordinates::fromArray($parentMeta);
        if (!$from instanceof BinlogCoordinates && (isset($parentMeta['binlog_file']) || isset($parentMeta['binlog_from']))) {
            throw new InvalidBackupOptionsException(sprintf('The binlog coordinates of backup %s are unreadable.', $parentId));
        }
        if (!$from instanceof BinlogCoordinates && $type === ChainLink::TYPE_FULL && is_string($parentMeta['database_dump'] ?? null)) {
            throw new InvalidBackupOptionsException(sprintf('Full backup %s holds a database dump without binlog coordinates: incrementals could not restore its database. Take a full backup with recordBinlogCoordinates.', $parentId));
        }

        $manifest = null;
        $includes = null;
        if (is_array($parentMeta['file_manifest'] ?? null)) {
            try {
                $manifest = FileManifest::fromArray($parentMeta['file_manifest']);
            } catch (InvalidArgumentException $e) {
                throw new InvalidBackupOptionsException(sprintf('The file manifest of backup %s is unreadable: %s', $parentId, $e->getMessage()), 0, $e);
            }
            if ($manifest->sha256() !== ($parentMeta['file_manifest_sha256'] ?? null)) {
                throw new InvalidBackupOptionsException(sprintf('The file manifest of backup %s does not match its file_manifest_sha256.', $parentId));
            }
            $includes = [];
            foreach (is_array($parentMeta['includes'] ?? null) ? $parentMeta['includes'] : [] as $path) {
                if (is_string($path)) {
                    $includes[] = $path;
                }
            }
        }
        if (!$from instanceof BinlogCoordinates && !$manifest instanceof FileManifest) {
            throw new InvalidBackupOptionsException(sprintf('Backup %s has neither binlog coordinates nor a file manifest to continue from.', $parentId));
        }

        $base ??= new BackupOptions();

        return new BackupOptions(
            includeDatabase: $from instanceof BinlogCoordinates,
            includeFiles: $manifest instanceof FileManifest,
            includePaths: $includes,
            format: $base->format,
            compressionLevel: $base->compressionLevel,
            label: $base->label,
            createdBy: $base->createdBy,
            heartbeat: $base->heartbeat,
            extraMeta: $base->extraMeta,
            password: $base->password,
            keyId: $base->keyId,
            baseManifest: $manifest,
            binlogFrom: $from,
            parentId: $parentId,
            chainId: $chainId,
        );
    }
}
