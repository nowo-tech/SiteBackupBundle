<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;

use function in_array;

/**
 * One part of a chain restore and what happened to it (1.36+).
 *
 * Parts: `database_dump` (the full backup's dump), `binlog` (an incremental's binary logs), `files` (the full backup's
 * files or an incremental's changed files), `deletions` (files an incremental recorded as deleted).
 */
final readonly class ChainRestoreStep
{
    public const PART_DATABASE_DUMP = 'database_dump';
    public const PART_BINLOG        = 'binlog';
    public const PART_FILES         = 'files';
    public const PART_DELETIONS     = 'deletions';

    /** Done. */
    public const APPLIED = 'applied';
    /** Planned but not done (an earlier step failed): the live site does not have it. */
    public const NOT_APPLIED = 'not_applied';
    /** Started and failed: the live site may have part of it. */
    public const FAILED = 'failed';
    /** Left out on purpose (after `--until`, or nothing to do). */
    public const SKIPPED = 'skipped';

    public function __construct(
        public string $backupId,
        public string $part,
        public string $status,
        public string $detail = '',
    ) {
        if (!in_array($part, [self::PART_DATABASE_DUMP, self::PART_BINLOG, self::PART_FILES, self::PART_DELETIONS], true)
            || !in_array($status, [self::APPLIED, self::NOT_APPLIED, self::FAILED, self::SKIPPED], true)) {
            throw new InvalidArgumentException('Unknown chain restore part or status.');
        }
    }

    public function with(string $status, ?string $detail = null): self
    {
        return new self($this->backupId, $this->part, $status, $detail ?? $this->detail);
    }
}
