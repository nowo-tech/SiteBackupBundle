<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Retention;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\BackupArtifact;

/**
 * One backup as the retention planner sees it (local artifact or remote object).
 */
final readonly class RetentionItem
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public bool $succeeded = true,
    ) {
    }

    /**
     * Local artifacts only exist once the archive is complete: they count as succeeded.
     */
    public static function fromArtifact(BackupArtifact $artifact): self
    {
        return new self($artifact->getId(), $artifact->getCreatedAt());
    }
}
