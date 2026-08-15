<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use RuntimeException;
use Throwable;

use function array_values;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Per-step setup journal in DBAL (survives wiping var/; audit + resume).
 *
 * Tables are created with runtime DDL ({@see ensureSchema}) — never Symfony Migrations —
 * because early wizard steps run before the host DB / migrations exist.
 */
final class DoctrineDbalSetupStepJournal
{
    public const TABLE = 'nowo_site_backup_setup_step';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    private bool $schemaEnsured = false;

    public function __construct(
        private readonly mixed $connection = null,
        private readonly string $tableName = self::TABLE,
    ) {
    }

    public function isUsable(): bool
    {
        return is_object($this->connection)
            && (method_exists($this->connection, 'executeQuery') || method_exists($this->connection, 'executeStatement'));
    }

    /**
     * Upsert rows from the aggregate {@see SetupProgress} snapshot.
     */
    public function sync(SetupProgress $progress): void
    {
        if (!$this->isUsable()) {
            return;
        }

        $this->ensureSchema();

        $profile = $progress->getProfile() !== '' ? $progress->getProfile() : 'default';
        $now     = ($progress->getUpdatedAt() ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $order   = 0;

        foreach ($progress->getCompletedStepIds() as $stepId) {
            if (!is_string($stepId) || $stepId === '') {
                continue;
            }
            $finishedAt = $progress->getCompletedAt()?->format('Y-m-d H:i:s') ?? $now;
            $this->upsert(
                profile: $profile,
                stepId: $stepId,
                status: self::STATUS_COMPLETED,
                stepOrder: $order,
                startedAt: $progress->getStartedAt()?->format('Y-m-d H:i:s'),
                finishedAt: $finishedAt,
                updatedAt: $now,
                message: null,
            );
            ++$order;
        }

        $current = $progress->getCurrentStepId();
        if (!is_string($current) || $current === '') {
            return;
        }

        $status = match ($progress->getPhase()) {
            SetupProgress::PHASE_FAILED    => self::STATUS_FAILED,
            SetupProgress::PHASE_COMPLETED => self::STATUS_COMPLETED,
            default                        => self::STATUS_RUNNING,
        };

        $finishedAt = null;
        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED) {
            $finishedAt = $progress->getCompletedAt()?->format('Y-m-d H:i:s') ?? $now;
        }

        $this->upsert(
            profile: $profile,
            stepId: $current,
            status: $status,
            stepOrder: $order,
            startedAt: $progress->getStartedAt()?->format('Y-m-d H:i:s'),
            finishedAt: $finishedAt,
            updatedAt: $now,
            message: $progress->getError() ?? $progress->getMessage(),
        );
    }

    /**
     * Merge completed step ids from the journal into progress (resume / thinner payload).
     */
    public function enrich(SetupProgress $progress): SetupProgress
    {
        if (!$this->isUsable()) {
            return $progress;
        }

        try {
            $this->ensureSchema();
        } catch (Throwable) {
            return $progress;
        }

        $profile = $progress->getProfile() !== '' ? $progress->getProfile() : null;
        $fromDb  = $this->listCompletedStepIds($profile);
        if ($fromDb === []) {
            return $progress;
        }

        $merged = $progress->getCompletedStepIds();
        foreach ($fromDb as $stepId) {
            if (!in_array($stepId, $merged, true)) {
                $merged[] = $stepId;
            }
        }

        if ($merged === $progress->getCompletedStepIds()) {
            return $progress;
        }

        return $progress->with(completedStepIds: $merged);
    }

    /**
     * @return list<string>
     */
    public function listCompletedStepIds(?string $profile = null): array
    {
        if (!$this->isUsable()) {
            return [];
        }

        try {
            $this->ensureSchema();
            $rows = $this->fetchAll($profile);
        } catch (Throwable) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== self::STATUS_COMPLETED) {
                continue;
            }
            $stepId = $row['step_id'] ?? null;
            if (is_string($stepId) && $stepId !== '') {
                $ids[] = $stepId;
            }
        }

        return array_values($ids);
    }

    /**
     * Latest finished step (by finished_at) for status / ops.
     *
     * @return array{profile: string, step_id: string, status: string, finished_at: ?string}|null
     */
    public function latestFinishedStep(?string $profile = null): ?array
    {
        if (!$this->isUsable()) {
            return null;
        }

        try {
            $this->ensureSchema();
            $rows = $this->fetchAll($profile);
        } catch (Throwable) {
            return null;
        }

        $best      = null;
        $bestTs    = null;
        $bestOrder = -1;
        foreach ($rows as $row) {
            $finished = $row['finished_at'] ?? null;
            if (!is_string($finished) || $finished === '') {
                continue;
            }
            $order = (int) ($row['step_order'] ?? 0);
            if ($bestTs === null || $finished > $bestTs || ($finished === $bestTs && $order >= $bestOrder)) {
                $bestTs    = $finished;
                $bestOrder = $order;
                $best      = [
                    'profile'     => (string) ($row['profile'] ?? ''),
                    'step_id'     => (string) ($row['step_id'] ?? ''),
                    'status'      => (string) ($row['status'] ?? ''),
                    'finished_at' => $finished,
                ];
            }
        }

        return $best;
    }

    public function clear(?string $profile = null): void
    {
        if (!$this->isUsable()) {
            return;
        }

        $this->ensureSchema();

        if (is_string($profile) && $profile !== '') {
            $this->executeStatement(
                sprintf('DELETE FROM %s WHERE profile = ?', $this->quoteTable()),
                [$profile],
            );

            return;
        }

        $this->executeStatement(sprintf('DELETE FROM %s', $this->quoteTable()), []);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured || !$this->isUsable()) {
            return;
        }

        // Runtime DDL only — host migrations must not own this table (cold-start wizard).
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                profile VARCHAR(64) NOT NULL,
                step_id VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL,
                step_order INTEGER NOT NULL DEFAULT 0,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                message CLOB DEFAULT NULL,
                PRIMARY KEY (profile, step_id)
            )',
            $this->quoteTable(),
        );

        $this->executeStatement($sql, []);
        $this->schemaEnsured = true;
    }

    private function upsert(
        string $profile,
        string $stepId,
        string $status,
        int $stepOrder,
        ?string $startedAt,
        ?string $finishedAt,
        string $updatedAt,
        ?string $message,
    ): void {
        $existing = $this->fetchOne($profile, $stepId);
        if ($existing === null) {
            $this->executeStatement(
                sprintf(
                    'INSERT INTO %s (profile, step_id, status, step_order, started_at, finished_at, updated_at, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    $this->quoteTable(),
                ),
                [$profile, $stepId, $status, $stepOrder, $startedAt, $finishedAt, $updatedAt, $message],
            );

            return;
        }

        $this->executeStatement(
            sprintf(
                'UPDATE %s SET status = ?, step_order = ?, started_at = ?, finished_at = ?, updated_at = ?, message = ? WHERE profile = ? AND step_id = ?',
                $this->quoteTable(),
            ),
            [$status, $stepOrder, $startedAt ?? ($existing['started_at'] ?? null), $finishedAt, $updatedAt, $message, $profile, $stepId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $profile, string $stepId): ?array
    {
        $connection = $this->connection;
        if (!is_object($connection) || !method_exists($connection, 'executeQuery')) {
            return null;
        }

        $result = $connection->executeQuery(
            sprintf(
                'SELECT profile, step_id, status, step_order, started_at, finished_at, updated_at, message FROM %s WHERE profile = ? AND step_id = ?',
                $this->quoteTable(),
            ),
            [$profile, $stepId],
        );

        if (is_object($result) && method_exists($result, 'fetchAssociative')) {
            $row = $result->fetchAssociative();

            return is_array($row) ? $row : null;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(?string $profile): array
    {
        $connection = $this->connection;
        if (!is_object($connection) || !method_exists($connection, 'executeQuery')) {
            return [];
        }

        if (is_string($profile) && $profile !== '') {
            $result = $connection->executeQuery(
                sprintf(
                    'SELECT profile, step_id, status, step_order, started_at, finished_at, updated_at, message FROM %s WHERE profile = ? ORDER BY step_order ASC, finished_at ASC',
                    $this->quoteTable(),
                ),
                [$profile],
            );
        } else {
            $result = $connection->executeQuery(
                sprintf(
                    'SELECT profile, step_id, status, step_order, started_at, finished_at, updated_at, message FROM %s ORDER BY step_order ASC, finished_at ASC',
                    $this->quoteTable(),
                ),
            );
        }

        if (!is_object($result)) {
            return [];
        }

        if (method_exists($result, 'fetchAllAssociative')) {
            /** @var mixed $all */
            $all = $result->fetchAllAssociative();
            if (!is_array($all)) {
                return [];
            }
            /** @var list<array<string, mixed>> $rows */
            $rows = [];
            foreach ($all as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        // Minimal fakes may only implement fetchAssociative in a loop-unfriendly way.
        if (method_exists($result, 'fetchAssociative')) {
            $rows = [];
            while (true) {
                $row = $result->fetchAssociative();
                if (!is_array($row)) {
                    break;
                }
                $rows[] = $row;
            }

            return $rows;
        }

        return [];
    }

    /**
     * @param list<mixed> $params
     */
    private function executeStatement(string $sql, array $params): void
    {
        $connection = $this->connection;
        if (!is_object($connection)) {
            throw new RuntimeException('No DBAL connection.');
        }

        if (method_exists($connection, 'executeStatement')) {
            $connection->executeStatement($sql, $params);

            return;
        }

        if (method_exists($connection, 'executeQuery')) {
            $connection->executeQuery($sql, $params);

            return;
        }

        throw new RuntimeException('DBAL connection cannot execute statements.');
    }

    private function quoteTable(): string
    {
        return $this->tableName;
    }
}
