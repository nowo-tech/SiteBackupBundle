<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use Nowo\SiteBackupBundle\Enum\ArchiveFormat;

/**
 * Result of {@see BackupArchiver::verifyFile()} (1.29+). `status` is also the exit code of
 * `nowo:site-backup:verify --file`.
 */
final readonly class VerifyFileResult
{
    public const OK             = 0;
    public const INTEGRITY      = 1;
    public const WRONG_PASSWORD = 2;
    public const UNSUPPORTED    = 3;
    public const SIGNATURE      = 4;

    /**
     * @param list<string> $errors
     * @param array<string, string> $checksums
     */
    public function __construct(
        public int $status,
        public array $errors = [],
        public array $checksums = [],
        public ?ArchiveFormat $format = null,
        public ?string $encryption = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
