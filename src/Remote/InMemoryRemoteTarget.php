<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;

use function fopen;
use function fwrite;
use function ksort;
use function rewind;
use function str_starts_with;
use function stream_get_contents;
use function strlen;

/**
 * {@see BackupRemoteTargetInterface} in memory, for tests and demos (1.29+). Not a service.
 */
final class InMemoryRemoteTarget implements BackupRemoteTargetInterface
{
    /** @var array<string, array{data: string, metadata: array<string, string>, at: DateTimeImmutable}> */
    private array $objects = [];

    public function __construct(private readonly bool $keepsMetadata = true)
    {
    }

    public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
    {
        $data = stream_get_contents($stream);
        if ($data === false) {
            throw new RemoteTargetException('Unable to read the upload stream.');
        }
        $this->objects[$key] = ['data' => $data, 'metadata' => $this->keepsMetadata ? $metadata : [], 'at' => new DateTimeImmutable()];

        return $this->object($key);
    }

    public function head(string $key): ?RemoteObject
    {
        return isset($this->objects[$key]) ? $this->object($key) : null;
    }

    public function list(string $prefix): iterable
    {
        ksort($this->objects);
        foreach ($this->objects as $key => $object) {
            if (str_starts_with($key, $prefix)) {
                yield $this->object($key);
            }
        }
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function openRead(string $key)
    {
        if (!isset($this->objects[$key])) {
            throw new RemoteTargetException('No such object.');
        }
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RemoteTargetException('Unable to open a memory stream.');
        }
        fwrite($stream, $this->objects[$key]['data']);
        rewind($stream);

        return $stream;
    }

    /**
     * Test helper: replaces an object's bytes (simulates corruption or tampering).
     */
    public function overwrite(string $key, string $data): void
    {
        if (isset($this->objects[$key])) {
            $this->objects[$key]['data'] = $data;
        }
    }

    private function object(string $key): RemoteObject
    {
        $object = $this->objects[$key];

        return new RemoteObject($key, strlen($object['data']), $object['at'], $object['metadata']);
    }
}
