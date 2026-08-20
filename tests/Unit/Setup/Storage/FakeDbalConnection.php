<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage;

/**
 * Minimal DBAL-like connection for unit tests (no doctrine/dbal required).
 *
 * Supports the progress singleton table and the per-step journal table.
 * Not final so coverage tests can override executeStatement/executeQuery.
 */
class FakeDbalConnection
{
    /** @var array<int, array<string, mixed>> */
    private array $progressRows = [];

    /** @var array<string, array<string, mixed>> keyed by profile\0step_id */
    private array $stepRows = [];

    private bool $progressTableReady = false;

    private bool $stepTableReady = false;

    /**
     * @param array<string, mixed> $row
     */
    public function seedRow(array $row): void
    {
        $this->progressTableReady = true;
        $this->progressRows[1]    = $row;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function stepRows(): array
    {
        return $this->stepRows;
    }

    /**
     * @param list<mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): int
    {
        $normalized = strtolower($sql);
        if (str_contains($normalized, 'create table')) {
            if (str_contains($normalized, 'step_order') || (str_contains($normalized, 'step_id') && !str_contains($normalized, 'current_step_id'))) {
                $this->stepTableReady = true;
            } else {
                $this->progressTableReady = true;
            }

            return 0;
        }

        if (str_contains($normalized, 'delete from') && (str_contains($normalized, 'setup_step') || str_contains($normalized, 'step_id') || str_contains($normalized, 'nowo_site_backup_setup_step') || $this->looksLikeStepTable($normalized))) {
            if (str_contains($normalized, 'where profile')) {
                $profile = (string) ($params[0] ?? '');
                foreach (array_keys($this->stepRows) as $key) {
                    if (str_starts_with($key, $profile . "\0")) {
                        unset($this->stepRows[$key]);
                    }
                }
            } else {
                $this->stepRows = [];
            }

            return 1;
        }

        if (str_contains($normalized, 'insert into') && $this->looksLikeStepTable($normalized)) {
            $profile                                   = (string) $params[0];
            $stepId                                    = (string) $params[1];
            $this->stepTableReady                      = true;
            $this->stepRows[$profile . "\0" . $stepId] = [
                'profile'     => $profile,
                'step_id'     => $stepId,
                'status'      => $params[2],
                'step_order'  => $params[3],
                'started_at'  => $params[4],
                'finished_at' => $params[5],
                'updated_at'  => $params[6],
                'message'     => $params[7],
            ];

            return 1;
        }

        if (str_starts_with(trim($normalized), 'update') && $this->looksLikeStepTable($normalized)) {
            $profile              = (string) $params[6];
            $stepId               = (string) $params[7];
            $key                  = $profile . "\0" . $stepId;
            $this->stepRows[$key] = [
                'profile'     => $profile,
                'step_id'     => $stepId,
                'status'      => $params[0],
                'step_order'  => $params[1],
                'started_at'  => $params[2],
                'finished_at' => $params[3],
                'updated_at'  => $params[4],
                'message'     => $params[5],
            ];

            return 1;
        }

        if (str_starts_with(trim($normalized), 'insert')) {
            $this->progressTableReady = true;
            $this->progressRows[1]    = [
                'phase'           => $params[1],
                'profile'         => $params[2],
                'current_step_id' => $params[3],
                'percent'         => $params[4],
                'started_at'      => $params[5],
                'updated_at'      => $params[6],
                'completed_at'    => $params[7],
                'payload'         => $params[8],
            ];

            return 1;
        }

        if (str_starts_with(trim($normalized), 'update')) {
            $this->progressRows[1] = [
                'phase'           => $params[0],
                'profile'         => $params[1],
                'current_step_id' => $params[2],
                'percent'         => $params[3],
                'started_at'      => $params[4],
                'updated_at'      => $params[5],
                'completed_at'    => $params[6],
                'payload'         => $params[7],
            ];

            return 1;
        }

        return 0;
    }

    /**
     * @param list<mixed> $params
     */
    public function executeQuery(string $sql, array $params = []): FakeDbalResult
    {
        $normalized = strtolower($sql);
        if (str_contains($normalized, 'create table')) {
            if (str_contains($normalized, 'step_order') || (str_contains($normalized, 'step_id') && !str_contains($normalized, 'current_step_id'))) {
                $this->stepTableReady = true;
            } else {
                $this->progressTableReady = true;
            }

            return new FakeDbalResult(null);
        }

        if ($this->looksLikeStepTable($normalized) && str_contains($normalized, 'select')) {
            if (!$this->stepTableReady) {
                return new FakeDbalResult(null, []);
            }

            if (str_contains($normalized, 'and step_id')) {
                $profile = (string) ($params[0] ?? '');
                $stepId  = (string) ($params[1] ?? '');
                $row     = $this->stepRows[$profile . "\0" . $stepId] ?? null;

                return new FakeDbalResult($row, $row !== null ? [$row] : []);
            }

            $rows = array_values($this->stepRows);
            if (str_contains($normalized, 'where profile') && isset($params[0])) {
                $profile = (string) $params[0];
                $rows    = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => ($row['profile'] ?? '') === $profile,
                ));
            }

            return new FakeDbalResult($rows[0] ?? null, $rows);
        }

        if (str_contains($normalized, 'select') && $this->progressTableReady && isset($this->progressRows[1])) {
            return new FakeDbalResult($this->progressRows[1]);
        }

        return new FakeDbalResult(null);
    }

    private function looksLikeStepTable(string $normalizedSql): bool
    {
        if (str_contains($normalizedSql, 'setup_step') || str_contains($normalizedSql, 'step_order')) {
            return true;
        }

        // Progress SQL uses current_step_id; journal uses bare step_id.
        return str_contains($normalizedSql, 'step_id') && !str_contains($normalizedSql, 'current_step_id');
    }
}
