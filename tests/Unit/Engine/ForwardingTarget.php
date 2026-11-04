<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Nowo\SiteBackupBundle\Remote\BackupRemoteTargetInterface;
use Nowo\SiteBackupBundle\Remote\InMemoryRemoteTarget;
use Nowo\SiteBackupBundle\Remote\RemoteObject;

/**
 * Test double: forwards to an {@see InMemoryRemoteTarget}; subclasses override one operation to misbehave.
 */
class ForwardingTarget implements BackupRemoteTargetInterface
{
    public function __construct(protected InMemoryRemoteTarget $inner = new InMemoryRemoteTarget())
    {
    }

    public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
    {
        return $this->inner->upload($stream, $key, $sizeBytes, $metadata);
    }

    public function head(string $key): ?RemoteObject
    {
        return $this->inner->head($key);
    }

    public function list(string $prefix): iterable
    {
        return $this->inner->list($prefix);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function openRead(string $key)
    {
        return $this->inner->openRead($key);
    }
}
