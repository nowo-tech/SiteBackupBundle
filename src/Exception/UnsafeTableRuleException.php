<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use InvalidArgumentException;

use function implode;
use function sprintf;

/**
 * A table rule that would break a restore, or that names something the dump cannot exclude.
 */
final class UnsafeTableRuleException extends InvalidArgumentException implements SiteBackupException
{
    public const UNKNOWN_TABLE    = 'unknown_table';
    public const NOT_A_BASE_TABLE = 'not_a_base_table';
    public const REQUIRED_TABLE   = 'required_table';
    public const REFERENCED_BY_FK = 'referenced_by_fk';
    public const USED_BY_VIEW     = 'used_by_view';

    /**
     * @param list<string> $related referencing tables or views (names only)
     */
    public function __construct(
        private readonly string $reason,
        private readonly string $table,
        private readonly array $related = [],
    ) {
        parent::__construct(self::describe($reason, $table, $related));
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return list<string>
     */
    public function getRelated(): array
    {
        return $this->related;
    }

    /**
     * @param list<string> $related
     */
    public static function describe(string $reason, string $table, array $related = []): string
    {
        return match ($reason) {
            self::UNKNOWN_TABLE    => sprintf('Table rule for "%s": no such table in the database.', $table),
            self::NOT_A_BASE_TABLE => sprintf('Table rule for "%s": it is a view, not a base table.', $table),
            self::REQUIRED_TABLE   => sprintf('Table "%s" is required for a restore and cannot be skipped or dumped without rows.', $table),
            self::REFERENCED_BY_FK => sprintf('Table "%s" cannot be skipped: foreign keys of %s reference it (use structure only).', $table, implode(', ', $related)),
            self::USED_BY_VIEW     => sprintf('Table "%s" cannot be skipped: view(s) %s use it (use structure only).', $table, implode(', ', $related)),
            default                => sprintf('Unsafe table rule for "%s" (%s).', $table, $reason),
        };
    }
}
