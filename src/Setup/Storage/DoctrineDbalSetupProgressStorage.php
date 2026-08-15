<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Storage;

use DateTimeImmutable;
use JsonException;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use RuntimeException;
use Throwable;

use function is_array;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function method_exists;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Persists setup progress in a singleton DBAL table (survives wiping var/).
 *
 * Soft-depends on Doctrine DBAL via duck-typing — no hard composer require.
 * Creates {@see self::TABLE} on first successful write when the connection works
 * (runtime DDL — not Symfony Migrations; early wizard steps may have no DB yet).
 *
 * When a {@see DoctrineDbalSetupStepJournal} is wired, each save also upserts
 * per-step rows (soft-fail) and load merges completed step ids from the journal.
 */
final class DoctrineDbalSetupProgressStorage implements SetupProgressStorageInterface
{
    public const TABLE = 'nowo_site_backup_setup_progress';

    private bool $schemaEnsured = false;

    public function __construct(
        private readonly mixed $connection = null,
        private readonly string $tableName = self::TABLE,
        private readonly ?DoctrineDbalSetupStepJournal $stepJournal = null,
        private readonly bool $stepRowsEnabled = true,
    ) {
    }

    public function load(): SetupProgress
    {
        if (!$this->isUsable()) {
            return new SetupProgress();
        }

        try {
            $this->ensureSchema();
            $row = $this->fetchRow();
            if ($row === null) {
                $progress = new SetupProgress();
            } else {
                $payload = $row['payload'] ?? null;
                if (is_string($payload) && $payload !== '') {
                    try {
                        /** @var array<string, mixed> $data */
                        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                        $progress = SetupProgress::fromArray($data);
                    } catch (JsonException) {
                        $progress = new SetupProgress();
                    }
                } else {
                    $progress = new SetupProgress();
                }
            }
        } catch (Throwable) {
            return new SetupProgress();
        }

        return $this->enrichFromStepJournal($progress);
    }

    public function save(SetupProgress $progress): void
    {
        if (!$this->isUsable()) {
            throw new RuntimeException('Doctrine DBAL connection is not available for setup progress storage.');
        }

        try {
            $this->ensureSchema();
            $json = json_encode($progress->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode setup progress: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to prepare setup progress table: ' . $e->getMessage(), 0, $e);
        }

        $params = [
            'id'              => 1,
            'phase'           => $progress->getPhase(),
            'profile'         => $progress->getProfile(),
            'current_step_id' => $progress->getCurrentStepId(),
            'percent'         => $progress->getPercent(),
            'started_at'      => $progress->getStartedAt()?->format('Y-m-d H:i:s'),
            'updated_at'      => ($progress->getUpdatedAt() ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'completed_at'    => $progress->getCompletedAt()?->format('Y-m-d H:i:s'),
            'payload'         => $json,
        ];

        try {
            if ($this->fetchRow() === null) {
                $this->executeStatement(
                    sprintf(
                        'INSERT INTO %s (id, phase, profile, current_step_id, percent, started_at, updated_at, completed_at, payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        $this->quoteTable(),
                    ),
                    [
                        $params['id'],
                        $params['phase'],
                        $params['profile'],
                        $params['current_step_id'],
                        $params['percent'],
                        $params['started_at'],
                        $params['updated_at'],
                        $params['completed_at'],
                        $params['payload'],
                    ],
                );
            } else {
                $this->executeStatement(
                    sprintf(
                        'UPDATE %s SET phase = ?, profile = ?, current_step_id = ?, percent = ?, started_at = ?, updated_at = ?, completed_at = ?, payload = ? WHERE id = 1',
                        $this->quoteTable(),
                    ),
                    [
                        $params['phase'],
                        $params['profile'],
                        $params['current_step_id'],
                        $params['percent'],
                        $params['started_at'],
                        $params['updated_at'],
                        $params['completed_at'],
                        $params['payload'],
                    ],
                );
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to persist setup progress to database: ' . $e->getMessage(), 0, $e);
        }

        $this->syncStepJournal($progress);
    }

    public function isUsable(): bool
    {
        return is_object($this->connection)
            && (method_exists($this->connection, 'executeQuery') || method_exists($this->connection, 'executeStatement'));
    }

    private function syncStepJournal(SetupProgress $progress): void
    {
        if (!$this->stepRowsEnabled || !$this->stepJournal instanceof DoctrineDbalSetupStepJournal) {
            return;
        }

        try {
            $idleEmpty = $progress->getPhase() === SetupProgress::PHASE_IDLE
                && $progress->getCompletedStepIds() === []
                && $progress->getCurrentStepId() === null;

            if ($idleEmpty) {
                $profile = $progress->getProfile() !== '' ? $progress->getProfile() : null;
                $this->stepJournal->clear($profile);

                return;
            }

            $this->stepJournal->sync($progress);
        } catch (Throwable) {
            // Soft-fail: singleton row remains source of truth; DB may still be cold mid-wizard.
        }
    }

    private function enrichFromStepJournal(SetupProgress $progress): SetupProgress
    {
        if (!$this->stepRowsEnabled || !$this->stepJournal instanceof DoctrineDbalSetupStepJournal) {
            return $progress;
        }

        try {
            return $this->stepJournal->enrich($progress);
        } catch (Throwable) {
            return $progress;
        }
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured || !$this->isUsable()) {
            return;
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id INTEGER NOT NULL PRIMARY KEY,
                phase VARCHAR(32) NOT NULL,
                profile VARCHAR(64) NOT NULL,
                current_step_id VARCHAR(128) DEFAULT NULL,
                percent DOUBLE PRECISION NOT NULL DEFAULT 0,
                started_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                payload CLOB NOT NULL
            )',
            $this->quoteTable(),
        );

        $this->executeStatement($sql, []);
        $this->schemaEnsured = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(): ?array
    {
        $connection = $this->connection;
        if (!is_object($connection) || !method_exists($connection, 'executeQuery')) {
            return null;
        }

        $result = $connection->executeQuery(
            sprintf('SELECT phase, profile, current_step_id, percent, started_at, updated_at, completed_at, payload FROM %s WHERE id = 1', $this->quoteTable()),
        );

        if (is_object($result) && method_exists($result, 'fetchAssociative')) {
            $row = $result->fetchAssociative();

            return is_array($row) ? $row : null;
        }

        return null;
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
        // Table name comes from bundle config (not user HTTP input).
        return $this->tableName;
    }
}
