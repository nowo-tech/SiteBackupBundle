<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupOptions;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a backup fails after {@see BackupStartedEvent} (1.28+). The original exception is rethrown to the
 * caller; the event only carries its class and a redacted message (≤ 500 chars), never the object or its trace.
 */
final class BackupFailedEvent extends Event
{
    /**
     * @param array<string, mixed> $options {@see BackupOptions::toLoggableArray()}
     */
    public function __construct(
        private readonly string $operationId,
        private readonly string $exceptionClass,
        private readonly string $message,
        private readonly array $options,
        private readonly ?string $actor = null,
    ) {
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }
}
