<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use function in_array;

/**
 * @param array<string, mixed> $answers
 * @param list<string> $completedStepIds
 * @param array<string, mixed> $options
 */
final class SetupContext
{
    /**
     * @param array<string, mixed> $answers
     * @param list<string> $completedStepIds
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $profile,
        private array $answers = [],
        private array $completedStepIds = [],
        private array $options = [],
    ) {
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getAnswer(string $key, mixed $default = null): mixed
    {
        return $this->answers[$key] ?? $default;
    }

    public function setAnswer(string $key, mixed $value): void
    {
        $this->answers[$key] = $value;
    }

    /**
     * @return list<string>
     */
    public function getCompletedStepIds(): array
    {
        return $this->completedStepIds;
    }

    public function markCompleted(string $stepId): void
    {
        if (!in_array($stepId, $this->completedStepIds, true)) {
            $this->completedStepIds[] = $stepId;
        }
    }

    public function isCompleted(string $stepId): bool
    {
        return in_array($stepId, $this->completedStepIds, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }

    public function wantsSampleData(): bool
    {
        return (bool) ($this->answers['sample_data'] ?? false);
    }
}
