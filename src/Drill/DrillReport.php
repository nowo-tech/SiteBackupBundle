<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Drill;

/**
 * Result of a {@see RestoreDrill} (1.29+). Table names and numbers only, no row data.
 */
final readonly class DrillReport
{
    /**
     * @param list<array{table: string, expected_rows: ?int, actual_rows: ?int, expected_checksum: ?string, actual_checksum: ?string}> $mismatches
     */
    public function __construct(
        public bool $passed,
        public int $tablesChecked,
        public array $mismatches,
        public float $durationSeconds,
    ) {
    }

    /**
     * @return array{passed: bool, tables_checked: int, mismatches: list<array{table: string, expected_rows: ?int, actual_rows: ?int, expected_checksum: ?string, actual_checksum: ?string}>, duration_seconds: float}
     */
    public function toArray(): array
    {
        return [
            'passed'           => $this->passed,
            'tables_checked'   => $this->tablesChecked,
            'mismatches'       => $this->mismatches,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
