<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

use Nowo\SiteBackupBundle\Exception\RemoteTargetException;

/**
 * Remote storage for backups, implemented by the host (1.29+): S3 through its own client, SFTP, a Flysystem
 * filesystem… The bundle ships no network client of its own.
 *
 * Host obligations:
 * - stream: never read a whole archive into memory; multipart parts ≥ 8 MiB; abort a failed multipart (no orphan parts);
 * - `upload()` either stores the whole object and returns it, or throws;
 * - `list()` returns keys under the prefix only and handles paging; `delete()` is idempotent;
 * - exceptions carry no credentials or signed URLs (the bundle redacts them again).
 */
interface BackupRemoteTargetInterface
{
    /**
     * @param resource $stream readable, positioned at 0
     * @param array<string, string> $metadata keys [a-z0-9-], ASCII values (≤ 2 KiB in total)
     *
     * @throws RemoteTargetException
     */
    public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject;

    /**
     * @throws RemoteTargetException
     */
    public function head(string $key): ?RemoteObject;

    /**
     * @throws RemoteTargetException
     *
     * @return iterable<RemoteObject>
     */
    public function list(string $prefix): iterable;

    /**
     * @throws RemoteTargetException
     */
    public function delete(string $key): void;

    /**
     * @throws RemoteTargetException
     *
     * @return resource readable stream
     */
    public function openRead(string $key);
}
