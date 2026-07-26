<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

final class SetupStepResult
{
    /**
     * @param list<string> $log
     */
    public function __construct(
        private readonly bool $success,
        private readonly string $message = '',
        private readonly array $log = [],
        private readonly bool $needsInput = false,
    ) {
    }

    /**
     * @param list<string> $log
     */
    public static function ok(string $message = '', array $log = []): self
    {
        /* @var list<string> $log */
        return new self(true, $message, $log);
    }

    /**
     * @param list<string> $log
     */
    public static function fail(string $message, array $log = []): self
    {
        /* @var list<string> $log */
        return new self(false, $message, $log);
    }

    public static function waitingForInput(string $message = ''): self
    {
        return new self(false, $message, [], true);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return list<string>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    public function isWaitingForInput(): bool
    {
        return $this->needsInput;
    }
}
