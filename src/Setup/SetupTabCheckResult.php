<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

/**
 * Result of a setup tab checker.
 */
final class SetupTabCheckResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_NEEDS_INPUT = 'needs_input';

    public const STATUS_BLOCKED = 'blocked';

    private function __construct(
        private readonly string $status,
        private readonly string $message = '',
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(self::STATUS_OK, $message);
    }

    public static function waitingForInput(string $message = ''): self
    {
        return new self(self::STATUS_NEEDS_INPUT, $message);
    }

    public static function blocked(string $message = ''): self
    {
        return new self(self::STATUS_BLOCKED, $message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function needsInput(): bool
    {
        return $this->status === self::STATUS_NEEDS_INPUT;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
