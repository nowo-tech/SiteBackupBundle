<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Model\BackupArtifact;

use function is_array;
use function is_int;
use function is_string;

use const DATE_ATOM;

/**
 * The chain view of one backup's meta (1.36+): what {@see ChainVerifier} checks and what a chain restore needs.
 * Built from the meta only; nothing here is trusted before the verifier and the archive checksums agree.
 */
final readonly class ChainLink
{
    public const TYPE_FULL        = 'full';
    public const TYPE_INCREMENTAL = 'incremental';

    /**
     * @param list<array{name: string, sha256: string, size: int}> $binlogFiles
     * @param array{path: string, sha256: string, size: int}|null $binlogEvents filtered captures: the schema-only events
     * @param string $storage how the archive is stored: format, encryption and key id (one per chain, PP-L10)
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public ?string $type,
        public ?string $chainId,
        public ?string $parentId,
        public bool $hasDump,
        public ?BinlogCoordinates $end,
        public ?BinlogCoordinates $from,
        public ?string $database,
        public array $binlogFiles,
        public bool $hasFileManifest,
        public ?string $fileManifestSha256,
        public ?string $baseManifestSha256,
        public ?DateTimeImmutable $firstEventAt = null,
        public ?DateTimeImmutable $lastEventAt = null,
        public ?string $binlogCapture = null,
        public ?array $binlogEvents = null,
        public ?DateTimeImmutable $snapshotAt = null,
        public string $storage = '',
    ) {
    }

    public static function fromArtifact(BackupArtifact $artifact): self
    {
        $meta = $artifact->getMeta();

        $files = [];
        foreach (is_array($meta['binlog_files'] ?? null) ? $meta['binlog_files'] : [] as $file) {
            if (is_array($file) && is_string($file['name'] ?? null) && is_string($file['sha256'] ?? null) && is_int($file['size'] ?? null)) {
                $files[] = ['name' => $file['name'], 'sha256' => $file['sha256'], 'size' => $file['size']];
            }
        }

        // An imported link keeps the creation time of the original backup (the import time says nothing of its data).
        $imported  = is_array($meta['imported_from'] ?? null) ? $meta['imported_from'] : [];
        $createdAt = self::date($imported, 'created_at') ?? $artifact->getCreatedAt();

        return new self(
            id: $artifact->getId(),
            createdAt: $createdAt,
            type: self::string($meta, 'type'),
            chainId: self::string($meta, 'chain_id'),
            parentId: self::string($meta, 'parent_id'),
            hasDump: is_string($meta['database_dump'] ?? null),
            end: BinlogCoordinates::fromArray($meta),
            from: is_array($meta['binlog_from'] ?? null) ? BinlogCoordinates::fromArray($meta['binlog_from']) : null,
            database: self::string($meta, 'binlog_database'),
            binlogFiles: $files,
            hasFileManifest: is_array($meta['file_manifest'] ?? null),
            fileManifestSha256: self::string($meta, 'file_manifest_sha256'),
            baseManifestSha256: self::string($meta, 'base_manifest_sha256'),
            firstEventAt: self::date($meta, 'first_event_at'),
            lastEventAt: self::date($meta, 'last_event_at'),
            binlogCapture: self::string($meta, 'binlog_capture'),
            binlogEvents: self::events($meta),
            snapshotAt: self::date($meta, 'snapshot_at'),
            // A sql.gz full backup continues as tar.gz (same container family and encryption).
            storage: ($artifact->getFormat() === ArchiveFormat::SqlGz ? ArchiveFormat::TarGz->value : $artifact->getFormat()->value)
                . ($artifact->isEncrypted() ? ' encrypted, key id "' . (string) ($meta['key_id'] ?? '') . '"' : ' plaintext'),
        );
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{path: string, sha256: string, size: int}|null
     */
    private static function events(array $meta): ?array
    {
        $events = $meta['binlog_events'] ?? null;
        if (!is_array($events) || !is_string($events['path'] ?? null) || !is_string($events['sha256'] ?? null) || !is_int($events['size'] ?? null)) {
            return null;
        }

        return ['path' => $events['path'], 'sha256' => $events['sha256'], 'size' => $events['size']];
    }

    public function isIncremental(): bool
    {
        return $this->type === self::TYPE_INCREMENTAL;
    }

    /**
     * True when this backup can start or continue a chain (a full with chain metadata, or an incremental).
     */
    public function isChainMember(): bool
    {
        return $this->chainId !== null && ($this->type === self::TYPE_FULL || $this->type === self::TYPE_INCREMENTAL);
    }

    public function hasBinlog(): bool
    {
        return $this->from instanceof BinlogCoordinates;
    }

    /**
     * @return list<string>
     */
    public function binlogFileNames(): array
    {
        $names = [];
        foreach ($this->binlogFiles as $file) {
            $names[] = $file['name'];
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function string(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $meta
     */
    private static function date(array $meta, string $key): ?DateTimeImmutable
    {
        $value = $meta[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $date instanceof DateTimeImmutable ? $date : null;
    }
}
