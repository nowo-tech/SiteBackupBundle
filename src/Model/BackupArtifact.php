<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;

use function is_array;
use function is_int;
use function is_string;

use const DATE_ATOM;

/**
 * Metadata for one integral site backup artifact on disk.
 */
final class BackupArtifact
{
    /**
     * @param array<string, string> $checksums path => sha256 hex
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $id,
        private readonly string $filename,
        private readonly string $absolutePath,
        private readonly DateTimeImmutable $createdAt,
        private readonly int $sizeBytes,
        private readonly string $archiveSha256,
        private readonly array $checksums = [],
        private readonly array $meta = [],
        private readonly ?string $label = null,
        private readonly ?string $createdBy = null,
    ) {
        if ($id === '' || $filename === '' || $absolutePath === '') {
            throw new InvalidArgumentException('Backup id, filename and path are required.');
        }
        if ($sizeBytes < 0) {
            throw new InvalidArgumentException('sizeBytes must be >= 0.');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getArchiveSha256(): string
    {
        return $this->archiveSha256;
    }

    /**
     * @return array<string, string>
     */
    public function getChecksums(): array
    {
        return $this->checksums;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    /**
     * Archive format (1.28+): `meta.format`, else the file name (1.27 archives are `tar.gz`).
     */
    public function getFormat(): ArchiveFormat
    {
        $format = $this->meta['format'] ?? null;
        if (is_string($format)) {
            $known = ArchiveFormat::tryFrom($format);
            if ($known instanceof ArchiveFormat) {
                return $known;
            }
        }

        return ArchiveFormat::fromFilename($this->filename);
    }

    /**
     * `database` | `files` | `database_files` (1.28+). 1.27 archives: derived from the presence of a dump.
     */
    public function getContent(): string
    {
        $content = $this->meta['content'] ?? null;
        if (is_string($content) && $content !== '') {
            return $content;
        }

        return isset($this->meta['database_dump']) ? 'database_files' : 'files';
    }

    /**
     * @return array<string, string> table => `skip` | `structure` (1.28+)
     */
    public function getTableRules(): array
    {
        $rules = [];
        $raw   = $this->meta['table_rules'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $table => $rule) {
                if (is_string($table) && is_string($rule)) {
                    $rules[$table] = $rule;
                }
            }
        }

        return $rules;
    }

    /**
     * Table-rule and secret-path warnings recorded at creation (1.28+); names and reasons only.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        $warnings = [];
        $raw      = $this->meta['warnings'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $warning) {
                if (is_string($warning)) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    /**
     * SHA-256 of the uncompressed SQL of a `sql.gz` archive (1.28+), else null.
     */
    public function getPlaintextSha256(): ?string
    {
        $sha = $this->meta['plaintext_sha256'] ?? null;

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /**
     * Always false in 1.28 (archive passwords arrive in 1.29).
     */
    public function isEncrypted(): bool
    {
        return ($this->meta['encrypted'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'filename'       => $this->filename,
            'absolute_path'  => $this->absolutePath,
            'created_at'     => $this->createdAt->format(DATE_ATOM),
            'size_bytes'     => $this->sizeBytes,
            'archive_sha256' => $this->archiveSha256,
            'checksums'      => $this->checksums,
            'meta'           => $this->meta,
            'label'          => $this->label,
            'created_by'     => $this->createdBy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $createdAtRaw = $data['created_at'] ?? null;
        $createdAt    = is_string($createdAtRaw)
            ? DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAtRaw)
            : null;
        if (!$createdAt instanceof DateTimeImmutable) {
            $createdAt = new DateTimeImmutable();
        }

        $size = $data['size_bytes'] ?? 0;
        if (!is_int($size)) {
            $size = (int) $size;
        }

        /** @var array<string, string> $checksums */
        $checksums = [];
        if (isset($data['checksums']) && is_array($data['checksums'])) {
            foreach ($data['checksums'] as $path => $hash) {
                if (is_string($path) && is_string($hash)) {
                    $checksums[$path] = $hash;
                }
            }
        }

        /** @var array<string, mixed> $meta */
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : '',
            absolutePath: is_string($data['absolute_path'] ?? null) ? $data['absolute_path'] : '',
            createdAt: $createdAt,
            sizeBytes: $size,
            archiveSha256: is_string($data['archive_sha256'] ?? null) ? $data['archive_sha256'] : '',
            checksums: $checksums,
            meta: $meta,
            label: is_string($data['label'] ?? null) ? $data['label'] : null,
            createdBy: is_string($data['created_by'] ?? null) ? $data['created_by'] : null,
        );
    }
}
