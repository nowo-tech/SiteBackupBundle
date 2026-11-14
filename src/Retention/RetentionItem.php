<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Retention;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\BackupArtifact;

use function is_string;

/**
 * One backup as the retention planner sees it (local artifact or remote object).
 */
final readonly class RetentionItem
{
    /**
     * @param string|null $chainId 1.36+: the chain the backup belongs to (`meta.chain_id`); chains are pruned whole
     * @param string|null $parentId 1.36+: the backup an incremental depends on (`meta.parent_id`)
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public bool $succeeded = true,
        public ?string $chainId = null,
        public ?string $parentId = null,
    ) {
    }

    /**
     * Local artifacts only exist once the archive is complete: they count as succeeded.
     */
    public static function fromArtifact(BackupArtifact $artifact): self
    {
        return self::fromMeta($artifact->getId(), $artifact->getCreatedAt(), $artifact->getMeta());
    }

    /**
     * 1.36+: an item with the chain fields of a backup meta (local sidecar or remote `.backup.json`).
     *
     * @param array<mixed> $meta
     */
    public static function fromMeta(string $id, DateTimeImmutable $createdAt, array $meta, bool $succeeded = true): self
    {
        $chainId  = $meta['chain_id'] ?? null;
        $parentId = $meta['parent_id'] ?? null;

        return new self(
            $id,
            $createdAt,
            $succeeded,
            is_string($chainId) && $chainId !== '' ? $chainId : null,
            is_string($parentId) && $parentId !== '' ? $parentId : null,
        );
    }
}
