<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

use DateTimeImmutable;
use JsonException;
use Nowo\SiteBackupBundle\Event\RemoteBackupPrunedEvent;
use Nowo\SiteBackupBundle\Event\RemoteBackupUploadedEvent;
use Nowo\SiteBackupBundle\Exception\RemoteTargetException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Retention\RetentionItem;
use Nowo\SiteBackupBundle\Retention\RetentionPlanner;
use Nowo\SiteBackupBundle\Retention\RetentionPolicy;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_substr;
use function preg_match;
use function rewind;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Upload → verify → sidecar, retention and orphan detection on any {@see BackupRemoteTargetInterface} (1.29+).
 *
 * Layout: `<key>` is the archive, `<key>.backup.json` its sidecar (no secrets, no local paths). The sidecar is written
 * only after the archive upload is verified, so a sidecar always means a complete archive; retention only ever counts
 * and deletes archives that have one, under the given prefix.
 */
final class RemoteBackupPublisher
{
    public const SIDECAR_SUFFIX = '.backup.json';

    public const META_PREFIX = 'nowo-';

    private const CHUNK_BYTES = 8 * 1024 * 1024;

    private const SIDECAR_MAX_BYTES = 64 * 1024;

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param array<string, string> $extraMetadata host metadata (keys [a-z0-9-], not starting with `nowo-`)
     *
     * @throws RemoteTargetException upload failure or verify mismatch (the object is deleted best effort, no sidecar)
     */
    public function publish(
        BackupArtifact $artifact,
        BackupRemoteTargetInterface $target,
        string $prefix,
        string $key,
        array $extraMetadata = [],
        VerifyMode $verify = VerifyMode::SizeAndMetadata,
    ): RemoteObject {
        self::assertKey($prefix, $key);
        $metadata = $this->metadata($artifact, $extraMetadata);

        $stream = @fopen($artifact->getAbsolutePath(), 'r');
        if ($stream === false) {
            throw new RemoteTargetException('The local archive is not readable.');
        }

        try {
            $target->upload($stream, $key, $artifact->getSizeBytes(), $metadata);
        } catch (Throwable $e) {
            $this->deleteQuietly($target, $key);

            throw self::wrap('upload', $e);
        } finally {
            fclose($stream);
        }

        try {
            $stored = $this->verify($artifact, $target, $key, $verify);
        } catch (RemoteTargetException $e) {
            $this->deleteQuietly($target, $key);

            throw $e;
        }

        $sidecar = $this->openMemory(self::encode($this->sidecar($artifact)));
        try {
            $target->upload($sidecar['stream'], $key . self::SIDECAR_SUFFIX, $sidecar['size'], ['nowo-backup-id' => $artifact->getId()]);
        } catch (Throwable $e) {
            throw self::wrap('sidecar upload', $e);
        } finally {
            fclose($sidecar['stream']);
        }

        $this->eventDispatcher?->dispatch(new RemoteBackupUploadedEvent($artifact, $stored));

        return $stored;
    }

    /**
     * Deletes what the policy drops among the archives with a sidecar under $prefix. Never runs without the just
     * created backup among them (a failed run prunes nothing), never deletes it nor the newest one.
     *
     * @param (callable(array<string, mixed>): bool)|null $filter only sidecars it accepts are considered (e.g. one plan)
     */
    public function prune(
        BackupRemoteTargetInterface $target,
        string $prefix,
        RetentionPolicy $policy,
        string $justCreatedId,
        ?callable $filter = null,
    ): PruneReport {
        $keysById = [];
        $items    = [];
        foreach ($this->catalogue($target, $prefix) as $archiveKey => $sidecar) {
            if ($filter !== null && !$filter($sidecar)) {
                continue;
            }
            $id        = (string) $sidecar['id'];
            $createdAt = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $sidecar['created_at']);
            if ($createdAt === false) {
                continue;
            }
            $keysById[$id] = $archiveKey;
            $items[]       = new RetentionItem($id, $createdAt);
        }

        $deleted = [];
        $errors  = [];
        foreach (RetentionPlanner::plan($items, $policy, $justCreatedId, $this->clock->now()) as $id) {
            $key = $keysById[$id];
            try {
                $target->delete($key);
                $target->delete($key . self::SIDECAR_SUFFIX);
                $deleted[] = $key;
            } catch (Throwable $e) {
                $errors[$key] = $e::class;
            }
        }

        $report = new PruneReport($deleted, $errors);
        $this->eventDispatcher?->dispatch(new RemoteBackupPrunedEvent($prefix, $report));

        return $report;
    }

    /**
     * Archives under $prefix without a sidecar, older than $olderThanSeconds: half-finished uploads of killed
     * workers. Never counted by retention; the host may delete them.
     *
     * @return list<RemoteObject>
     */
    public function orphans(BackupRemoteTargetInterface $target, string $prefix, int $olderThanSeconds = 86400): array
    {
        $objects = [];
        foreach ($this->listSafely($target, $prefix) as $object) {
            $objects[$object->key] = $object;
        }

        $limit   = $this->clock->now()->getTimestamp() - $olderThanSeconds;
        $orphans = [];
        foreach ($objects as $key => $object) {
            if (str_ends_with($key, self::SIDECAR_SUFFIX) || isset($objects[$key . self::SIDECAR_SUFFIX])) {
                continue;
            }
            if ($object->lastModified->getTimestamp() <= $limit) {
                $orphans[] = $object;
            }
        }

        return $orphans;
    }

    /**
     * The remote sidecar of an artifact: no secrets, no local paths.
     *
     * @return array<string, mixed>
     */
    public function sidecar(BackupArtifact $artifact): array
    {
        $meta = $artifact->getMeta();

        return [
            'id'                => $artifact->getId(),
            'created_at'        => $artifact->getCreatedAt()->format(DATE_ATOM),
            'format'            => $artifact->getFormat()->value,
            'encrypted'         => $artifact->isEncrypted(),
            'encryption'        => is_string($meta['encryption'] ?? null) ? $meta['encryption'] : null,
            'key_id'            => is_string($meta['key_id'] ?? null) ? $meta['key_id'] : null,
            'content'           => $artifact->getContent(),
            'compression_level' => is_int($meta['compression_level'] ?? null) ? $meta['compression_level'] : null,
            'size'              => $artifact->getSizeBytes(),
            'sha256'            => $artifact->getArchiveSha256(),
            'plaintext_sha256'  => $artifact->getPlaintextSha256(),
            'table_rules'       => $artifact->getTableRules(),
            'warnings'          => $artifact->getWarnings(),
            'bundle_version'    => is_string($meta['bundle_version'] ?? null) ? $meta['bundle_version'] : null,
            'manifest_version'  => is_int($meta['manifest_version'] ?? null) ? $meta['manifest_version'] : 1,
            'label'             => $artifact->getLabel(),
            'created_by'        => $artifact->getCreatedBy(),
            'extra_meta'        => is_array($meta['extra'] ?? null) ? $meta['extra'] : [],
            'checksums_sha256'  => BackupSigner::checksumsDigest($artifact->getChecksums()),
            'signature'         => is_string($meta['signature'] ?? null) ? $meta['signature'] : null,
            'signing_key_id'    => is_string($meta['signing_key_id'] ?? null) ? $meta['signing_key_id'] : null,
        ];
    }

    /**
     * @throws RemoteTargetException
     */
    public static function assertKey(string $prefix, string $key): void
    {
        foreach (['prefix' => $prefix, 'key' => $key] as $name => $value) {
            if (strlen($value) > 1024 || str_starts_with($value, '/') || str_contains('/' . $value . '/', '/../') || str_contains('/' . $value . '/', '/./') || preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
                throw new RemoteTargetException(sprintf('Invalid remote %s.', $name));
            }
        }
        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            throw new RemoteTargetException('The remote prefix must be empty or end with "/".');
        }
        if ($key === $prefix || !str_starts_with($key, $prefix) || str_ends_with($key, '/') || str_ends_with($key, self::SIDECAR_SUFFIX)) {
            throw new RemoteTargetException('The remote key must name a file under the prefix.');
        }
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function metadata(BackupArtifact $artifact, array $extra): array
    {
        $metadata = [
            'nowo-backup-id'      => $artifact->getId(),
            'nowo-sha256'         => $artifact->getArchiveSha256(),
            'nowo-size'           => (string) $artifact->getSizeBytes(),
            'nowo-format'         => $artifact->getFormat()->value,
            'nowo-encrypted'      => $artifact->isEncrypted() ? '1' : '0',
            'nowo-content'        => $artifact->getContent(),
            'nowo-bundle-version' => (string) ($artifact->getMeta()['bundle_version'] ?? 'unknown'),
        ];
        $keyId = $artifact->getMeta()['key_id'] ?? null;
        if (is_string($keyId) && $keyId !== '') {
            $metadata['nowo-key-id'] = $keyId;
        }

        foreach ($extra as $name => $value) {
            if (!is_string($name) || preg_match('/^[a-z0-9-]{1,64}$/', $name) !== 1 || str_starts_with($name, self::META_PREFIX)) {
                throw new RemoteTargetException('Extra metadata keys must match [a-z0-9-]{1,64} and must not start with "nowo-".');
            }
            if (!is_string($value) || preg_match('/^[\x20-\x7E]{0,512}$/', $value) !== 1) {
                throw new RemoteTargetException(sprintf('Extra metadata "%s" must be printable ASCII (≤ 512 chars).', $name));
            }
            $metadata[$name] = $value;
        }

        return $metadata;
    }

    private function verify(BackupArtifact $artifact, BackupRemoteTargetInterface $target, string $key, VerifyMode $mode): RemoteObject
    {
        try {
            $stored = $target->head($key);
        } catch (Throwable $e) {
            throw self::wrap('head', $e);
        }

        if (!$stored instanceof RemoteObject || $stored->sizeBytes !== $artifact->getSizeBytes()) {
            throw new RemoteTargetException('Upload verification failed: size mismatch or object missing.', 1);
        }

        $sha = $stored->metadata['nowo-sha256'] ?? null;
        if ($mode === VerifyMode::SizeAndMetadata && is_string($sha) && $sha !== $artifact->getArchiveSha256()) {
            throw new RemoteTargetException('Upload verification failed: sha256 metadata mismatch.', 1);
        }

        if ($mode === VerifyMode::FullRehash && $this->remoteSha256($target, $key) !== $artifact->getArchiveSha256()) {
            throw new RemoteTargetException('Upload verification failed: the stored bytes differ.', 1);
        }

        return $stored;
    }

    private function remoteSha256(BackupRemoteTargetInterface $target, string $key): string
    {
        try {
            $stream = $target->openRead($key);
        } catch (Throwable $e) {
            throw self::wrap('read', $e);
        }

        try {
            $context = hash_init('sha256');
            while (!feof($stream)) {
                hash_update($context, (string) fread($stream, self::CHUNK_BYTES));
            }

            return hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Archive key => sidecar, for archives under $prefix that have a readable sidecar.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(BackupRemoteTargetInterface $target, string $prefix): array
    {
        $keys = [];
        foreach ($this->listSafely($target, $prefix) as $object) {
            $keys[$object->key] = true;
        }

        $catalogue = [];
        foreach ($keys as $key => $_) {
            if (!str_ends_with($key, self::SIDECAR_SUFFIX)) {
                continue;
            }
            $archiveKey = substr($key, 0, -strlen(self::SIDECAR_SUFFIX));
            if (!isset($keys[$archiveKey])) {
                continue;
            }
            $sidecar = $this->readSidecar($target, $key);
            if ($sidecar !== null) {
                $catalogue[$archiveKey] = $sidecar;
            }
        }

        return $catalogue;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSidecar(BackupRemoteTargetInterface $target, string $key): ?array
    {
        try {
            $stream = $target->openRead($key);
            $raw    = (string) fread($stream, self::SIDECAR_MAX_BYTES + 1);
            fclose($stream);
            if (strlen($raw) > self::SIDECAR_MAX_BYTES) {
                return null;
            }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['created_at'] ?? null)) {
            return null;
        }

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return iterable<RemoteObject>
     */
    private function listSafely(BackupRemoteTargetInterface $target, string $prefix): iterable
    {
        if ($prefix !== '') {
            self::assertKey($prefix, $prefix . 'x');
        }

        try {
            foreach ($target->list($prefix) as $object) {
                if (str_starts_with($object->key, $prefix)) {
                    yield $object;
                }
            }
        } catch (Throwable $e) {
            throw self::wrap('list', $e);
        }
    }

    private function deleteQuietly(BackupRemoteTargetInterface $target, string $key): void
    {
        try {
            $target->delete($key);
        } catch (Throwable) {
            // Best effort: the orphan scan reports what is left.
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RemoteTargetException('Unable to encode the sidecar.', 0, $e);
        }
    }

    /**
     * @return array{stream: resource, size: int}
     */
    private function openMemory(string $data): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RemoteTargetException('Unable to open a temporary stream.');
        }
        fwrite($stream, $data);
        rewind($stream);

        return ['stream' => $stream, 'size' => strlen($data)];
    }

    private static function wrap(string $operation, Throwable $e): RemoteTargetException
    {
        if ($e instanceof RemoteTargetException) {
            return $e;
        }

        // Not chained: a host client's message or trace may hold signed URLs or headers.
        return new RemoteTargetException(sprintf('Remote %s failed (%s): %s', $operation, $e::class, mb_substr(SecretRedactor::redact($e->getMessage()), 0, 300)));
    }
}
