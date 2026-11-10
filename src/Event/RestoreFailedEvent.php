<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Event;

use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use SensitiveParameter;
use Symfony\Contracts\EventDispatcher\Event;
use Throwable;

/**
 * Dispatched when a restore fails after {@see RestoreStartedEvent}. The original exception is rethrown to the caller.
 *
 * {@see self::getError()} is the redacted, capped message ({@see SecretRedactor::fromThrowable()}, ≤ 500 chars, 1.34+):
 * safe to log, store or forward. Before 1.34 it was the raw exception message, which may quote database credentials
 * (DSN, `password=`, a failing `ALTER USER … PASSWORD '…'` statement).
 *
 * {@see self::getException()} (1.34+) is the original throwable, kept in memory only for listeners that need the type
 * or the chain. SENSITIVE: its message and trace are NOT redacted. Never persist, serialize, forward it to a third
 * party or render it; use {@see self::getError()} / {@see self::getExceptionClass()} for that.
 */
final class RestoreFailedEvent extends Event
{
    public function __construct(
        private readonly BackupArtifact $artifact,
        private readonly string $error,
        private readonly ?string $actor = null,
        #[SensitiveParameter]
        private readonly ?Throwable $exception = null,
    ) {
    }

    public function getArtifact(): BackupArtifact
    {
        return $this->artifact;
    }

    /**
     * Redacted and capped failure message (1.34+), safe to persist or display.
     */
    public function getError(): string
    {
        return $this->error;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    /**
     * The original throwable (1.34+), or null when the dispatcher did not pass one. SENSITIVE: unredacted message and
     * trace; in-memory use only (never persist, serialize, forward or render it).
     */
    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    /**
     * Class of the original throwable (1.34+), or null when none was passed.
     */
    public function getExceptionClass(): ?string
    {
        return $this->exception instanceof Throwable ? $this->exception::class : null;
    }
}
