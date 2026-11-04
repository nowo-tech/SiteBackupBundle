<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Model\BackupOptions;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once the operation lock is held, before anything is dumped or copied (1.28+).
 */
final class BackupStartedEvent extends Event
{
    /**
     * @param array<string, mixed> $options {@see BackupOptions::toLoggableArray()}
     */
    public function __construct(
        private readonly string $operationId,
        private readonly array $options,
        private readonly ?string $actor = null,
    ) {
    }

    public function getOperationId(): string
    {
        return $this->operationId;
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
