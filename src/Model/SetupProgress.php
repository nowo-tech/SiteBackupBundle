<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use DateTimeImmutable;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

use const DATE_ATOM;

final class SetupProgress
{
    public const PHASE_IDLE      = 'idle';
    public const PHASE_RUNNING   = 'running';
    public const PHASE_WAITING   = 'waiting_input';
    public const PHASE_COMPLETED = 'completed';
    public const PHASE_FAILED    = 'failed';

    /**
     * @param list<string> $completedStepIds
     * @param list<string> $log
     * @param array<string, mixed> $answers
     */
    public function __construct(
        private readonly string $phase = self::PHASE_IDLE,
        private readonly string $profile = 'fresh_install',
        private readonly ?string $currentStepId = null,
        private readonly float $percent = 0.0,
        private readonly ?string $message = null,
        private readonly ?string $error = null,
        private readonly array $completedStepIds = [],
        private readonly array $log = [],
        private readonly array $answers = [],
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getCurrentStepId(): ?string
    {
        return $this->currentStepId;
    }

    public function getPercent(): float
    {
        return $this->percent;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return list<string>
     */
    public function getCompletedStepIds(): array
    {
        return $this->completedStepIds;
    }

    /**
     * @return list<string>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isFinished(): bool
    {
        return $this->phase === self::PHASE_COMPLETED;
    }

    /**
     * @param list<string>|null $completedStepIds
     * @param list<string>|null $log
     * @param array<string, mixed>|null $answers
     */
    public function with(
        ?string $phase = null,
        ?string $profile = null,
        ?string $currentStepId = null,
        bool $clearCurrentStepId = false,
        ?float $percent = null,
        ?string $message = null,
        bool $clearMessage = false,
        ?string $error = null,
        bool $clearError = false,
        ?array $completedStepIds = null,
        ?array $log = null,
        ?array $answers = null,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        /** @var list<string> $resolvedCompleted */
        $resolvedCompleted = $completedStepIds ?? $this->completedStepIds;
        /** @var list<string> $resolvedLog */
        $resolvedLog = $log ?? $this->log;
        /** @var array<string, mixed> $resolvedAnswers */
        $resolvedAnswers = $answers ?? $this->answers;

        return new self(
            phase: $phase ?? $this->phase,
            profile: $profile ?? $this->profile,
            currentStepId: $clearCurrentStepId ? null : ($currentStepId ?? $this->currentStepId),
            percent: $percent ?? $this->percent,
            message: $clearMessage ? null : ($message ?? $this->message),
            error: $clearError ? null : ($error ?? $this->error),
            completedStepIds: $resolvedCompleted,
            log: $resolvedLog,
            answers: $resolvedAnswers,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phase'              => $this->phase,
            'profile'            => $this->profile,
            'current_step_id'    => $this->currentStepId,
            'percent'            => $this->percent,
            'message'            => $this->message,
            'error'              => $this->error,
            'completed_step_ids' => $this->completedStepIds,
            'log'                => $this->log,
            'answers'            => $this->answers,
            'updated_at'         => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $updatedAt = null;
        if (is_string($data['updated_at'] ?? null)) {
            $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $data['updated_at']);
            if ($parsed instanceof DateTimeImmutable) {
                $updatedAt = $parsed;
            }
        }

        /** @var list<string> $completed */
        $completed = [];
        if (isset($data['completed_step_ids']) && is_array($data['completed_step_ids'])) {
            foreach ($data['completed_step_ids'] as $id) {
                if (is_string($id)) {
                    $completed[] = $id;
                }
            }
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

        /** @var array<string, mixed> $answers */
        $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];

        $percent = $data['percent'] ?? 0.0;

        return new self(
            phase: is_string($data['phase'] ?? null) ? $data['phase'] : self::PHASE_IDLE,
            profile: is_string($data['profile'] ?? null) ? $data['profile'] : 'fresh_install',
            currentStepId: is_string($data['current_step_id'] ?? null) ? $data['current_step_id'] : null,
            percent: is_float($percent) || is_int($percent) ? (float) $percent : 0.0,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            completedStepIds: $completed,
            log: $log,
            answers: $answers,
            updatedAt: $updatedAt,
        );
    }
}
