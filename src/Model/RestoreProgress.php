<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use DateTimeImmutable;
use InvalidArgumentException;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

use const DATE_ATOM;

/**
 * Progress / lifecycle of a restore operation (served by the loading page UI).
 */
final class RestoreProgress
{
    public const PHASE_IDLE       = 'idle';
    public const PHASE_PREPARING  = 'preparing';
    public const PHASE_VALIDATING = 'validating';
    public const PHASE_EXTRACTING = 'extracting';
    public const PHASE_APPLYING   = 'applying';
    public const PHASE_FINALIZING = 'finalizing';
    public const PHASE_COMPLETED  = 'completed';
    public const PHASE_FAILED     = 'failed';

    /**
     * @param list<string> $log
     */
    public function __construct(
        private readonly bool $active = false,
        private readonly string $phase = self::PHASE_IDLE,
        private readonly float $percent = 0.0,
        private readonly ?string $message = null,
        private readonly ?string $backupId = null,
        private readonly ?string $error = null,
        private readonly array $log = [],
        private readonly ?DateTimeImmutable $startedAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private readonly ?DateTimeImmutable $finishedAt = null,
    ) {
        if ($percent < 0.0 || $percent > 100.0) {
            throw new InvalidArgumentException('percent must be between 0 and 100.');
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getPercent(): float
    {
        return $this->percent;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getBackupId(): ?string
    {
        return $this->backupId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return list<string>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @param list<string>|null $log
     */
    public function with(
        ?bool $active = null,
        ?string $phase = null,
        ?float $percent = null,
        ?string $message = null,
        bool $clearMessage = false,
        ?string $backupId = null,
        bool $clearBackupId = false,
        ?string $error = null,
        bool $clearError = false,
        ?array $log = null,
        ?DateTimeImmutable $startedAt = null,
        bool $clearStartedAt = false,
        ?DateTimeImmutable $updatedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        bool $clearFinishedAt = false,
    ): self {
        /** @var list<string> $resolvedLog */
        $resolvedLog = $log ?? $this->log;

        return new self(
            active: $active ?? $this->active,
            phase: $phase ?? $this->phase,
            percent: $percent ?? $this->percent,
            message: $clearMessage ? null : ($message ?? $this->message),
            backupId: $clearBackupId ? null : ($backupId ?? $this->backupId),
            error: $clearError ? null : ($error ?? $this->error),
            log: $resolvedLog,
            startedAt: $clearStartedAt ? null : ($startedAt ?? $this->startedAt),
            updatedAt: $updatedAt ?? $this->updatedAt,
            finishedAt: $clearFinishedAt ? null : ($finishedAt ?? $this->finishedAt),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active'      => $this->active,
            'phase'       => $this->phase,
            'percent'     => $this->percent,
            'message'     => $this->message,
            'backup_id'   => $this->backupId,
            'error'       => $this->error,
            'log'         => $this->log,
            'started_at'  => $this->startedAt?->format(DATE_ATOM),
            'updated_at'  => $this->updatedAt?->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $startedAt  = self::parseDate($data['started_at'] ?? null);
        $updatedAt  = self::parseDate($data['updated_at'] ?? null);
        $finishedAt = self::parseDate($data['finished_at'] ?? null);
        $percent    = $data['percent'] ?? 0.0;
        if (is_int($percent)) {
            $percent = (float) $percent;
        }
        if (!is_float($percent)) {
            $percent = 0.0;
        }

        /** @var list<string> $log */
        $log = [];
        if (isset($data['log']) && is_array($data['log'])) {
            foreach ($data['log'] as $line) {
                if (is_string($line)) {
                    $log[] = $line;
                }
            }
        }

        return new self(
            active: (bool) ($data['active'] ?? false),
            phase: is_string($data['phase'] ?? null) ? $data['phase'] : self::PHASE_IDLE,
            percent: $percent,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            backupId: is_string($data['backup_id'] ?? null) ? $data['backup_id'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            log: $log,
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            finishedAt: $finishedAt,
        );
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}
