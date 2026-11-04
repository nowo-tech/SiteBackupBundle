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

## Flysystem

With league/flysystem 3 installed, point `nowo_site_backup.backup.remote.flysystem_storage` at a
`League\Flysystem\FilesystemOperator` service: `FlysystemRemoteTarget` is registered and aliased to
`BackupRemoteTargetInterface`. Flysystem cannot read object metadata back, so the `.backup.json` sidecar carries the
checksum (`VerifyMode::SizeAndMetadata` falls back to the size; use `FullRehash` for periodic checks).

## Signatures (optional)

With `backup.signing.secret_key` set (base64 Ed25519 seed from `nowo:site-backup:signing-keygen`, kept in the
environment), every backup is signed and the sidecars carry `signature` / `signing_key_id`. Verifiers list the public
keys in `backup.signing.public_keys`; `verifyIntegrity()` and `nowo:site-backup:verify --file … --sidecar …` reject a
modified sidecar (exit code 4).

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
