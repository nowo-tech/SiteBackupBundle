# Remote targets (1.29+)

The bundle ships the interface and the upload / verify / retention logic; the host ships the storage client.

## Implement `BackupRemoteTargetInterface`

```php
final class S3BackupTarget implements BackupRemoteTargetInterface
{
    public function upload($stream, string $key, int $sizeBytes, array $metadata): RemoteObject { /* multipart from the stream */ }
    public function head(string $key): ?RemoteObject { /* size + x-amz-meta-* */ }
    public function list(string $prefix): iterable { /* paged ListObjectsV2, keys under $prefix only */ }
    public function delete(string $key): void { /* idempotent */ }
    public function openRead(string $key) { /* readable stream */ }
}
```

Obligations: stream (never read a whole archive into memory; parts ≥ 8 MiB; abort a failed multipart); `upload()`
stores everything or throws; `list()` stays under the prefix; exceptions carry no credentials or signed URLs (the
bundle redacts them again and never chains them). `InMemoryRemoteTarget` is a reference implementation for tests.

## Publish, verify, prune

```php
$stored = $publisher->publish($artifact, $target, 'backups/prod/', 'backups/prod/2026/11/nightly-….zip',
    ['beacon-run' => $runId], VerifyMode::SizeAndMetadata);
$report = $publisher->prune($target, 'backups/prod/', new RetentionPolicy(keepLast: 7), $artifact->getId());
$orphans = $publisher->orphans($target, 'backups/prod/');
```

- Metadata: `nowo-backup-id`, `nowo-sha256`, `nowo-size`, `nowo-format`, `nowo-encrypted`, `nowo-content`,
  `nowo-bundle-version`, `nowo-key-id` (when set), plus yours (`[a-z0-9-]`, not `nowo-*`).
- `<key>.backup.json` sidecar (no secrets, no local paths) is written **after** the upload is verified, so retention
  and `importArchive()` work on targets without native metadata (SFTP, local disks). A failed or mismatched upload is
  deleted best effort and gets no sidecar.
- `VerifyMode::FullRehash` downloads the object again: use it for periodic checks, not every run.
- `prune()` only considers archives with a sidecar under the prefix; it never deletes the backup just created or the
  newest one, and deletes nothing when the just-created id is not there (failed run). A delete error is reported in
  `PruneReport::$errors` and retried next time.
