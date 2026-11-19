<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Concurrency;

use RuntimeException;

use function str_contains;
use function str_starts_with;

/**
 * A DBAL stand-in where another process inserts the same row between our SELECT and our INSERT.
 */
final class RacingConnection
{
    public int $failedInserts = 0;

    public int $updates = 0;

    /** @var array<string, mixed>|null */
    private ?array $row = null;

    public function __construct(private readonly string $table, private readonly bool $insertRow = true)
    {
    }

    public function executeQuery(string $sql): object
    {
        $row = str_contains($sql, $this->table) ? $this->row : null;

        return new class($row) {
            /**
             * @param array<string, mixed>|null $row
             */
            public function __construct(private readonly ?array $row)
            {
            }

            /**
             * @return array<string, mixed>|false
             */
            public function fetchAssociative(): array|false
            {
                return $this->row ?? false;
            }

            /**
             * @return list<array<string, mixed>>
             */
            public function fetchAllAssociative(): array
            {
                return $this->row === null ? [] : [$this->row];
            }
        };
    }

    public function executeStatement(string $sql): int
    {
        if (str_starts_with($sql, 'INSERT')) {
            ++$this->failedInserts;
            if ($this->insertRow) {
                $this->row = ['phase' => 'running', 'profile' => 'p', 'payload' => '{}', 'started_at' => null, 'status' => 'running'];
            }

            throw new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        }
        if (str_starts_with($sql, 'UPDATE')) {
            ++$this->updates;
        }

        return 0;
    }
}
