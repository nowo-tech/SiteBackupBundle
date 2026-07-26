<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use DateTimeImmutable;

use function is_array;
use function is_string;

use const DATE_ATOM;

final class BackupHistoryEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $action,
        private readonly DateTimeImmutable $occurredAt,
        private readonly ?string $actor = null,
        private readonly ?string $backupId = null,
        private readonly ?string $message = null,
        private readonly array $context = [],
    ) {
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getBackupId(): ?string
    {
        return $this->backupId;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action'      => $this->action,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'actor'       => $this->actor,
            'backup_id'   => $this->backupId,
            'message'     => $this->message,
            'context'     => $this->context,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $at = is_string($data['occurred_at'] ?? null)
            ? DateTimeImmutable::createFromFormat(DATE_ATOM, $data['occurred_at'])
            : null;

        return new self(
            action: is_string($data['action'] ?? null) ? $data['action'] : 'unknown',
            occurredAt: $at instanceof DateTimeImmutable ? $at : new DateTimeImmutable(),
            actor: is_string($data['actor'] ?? null) ? $data['actor'] : null,
            backupId: is_string($data['backup_id'] ?? null) ? $data['backup_id'] : null,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            context: isset($data['context']) && is_array($data['context']) ? $data['context'] : [],
        );
    }
}
