<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

use DateTimeImmutable;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;

use function rtrim;
use function str_starts_with;

/**
 * {@see BackupRemoteTargetInterface} over any Flysystem 3 filesystem (S3, SFTP, local, in-memory…) (1.29+, optional:
 * needs league/flysystem).
 *
 * Flysystem cannot read custom object metadata back, so {@see RemoteObject::$metadata} is always empty here; the
 * `.backup.json` sidecar written by {@see RemoteBackupPublisher} carries the checksum and the backup id instead.
 * Streams are passed straight to `writeStream()` / returned by `readStream()` (no full read into memory).
 */
final class FlysystemRemoteTarget implements BackupRemoteTargetInterface
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject
    {
        $this->filesystem->writeStream($key, $stream);

        return $this->head($key) ?? throw new RemoteTargetException('The uploaded object is missing.');
    }

    public function head(string $key): ?RemoteObject
    {
        if (!$this->filesystem->fileExists($key)) {
            return null;
        }

        return new RemoteObject($key, $this->filesystem->fileSize($key), (new DateTimeImmutable())->setTimestamp($this->filesystem->lastModified($key)));
    }

    public function list(string $prefix): iterable
    {
        $directory = rtrim($prefix, '/');
        foreach ($this->filesystem->listContents($directory, true) as $item) {
            if ($item instanceof FileAttributes && str_starts_with($item->path(), $prefix)) {
                yield new RemoteObject(
                    $item->path(),
                    $item->fileSize() ?? $this->filesystem->fileSize($item->path()),
                    (new DateTimeImmutable())->setTimestamp($item->lastModified() ?? $this->filesystem->lastModified($item->path())),
                );
            }
        }
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }

    public function openRead(string $key)
    {
        return $this->filesystem->readStream($key);
    }
}
