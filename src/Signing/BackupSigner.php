<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Signing;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function function_exists;
use function hash;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function preg_match;
use function random_bytes;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_publickey_from_secretkey;
use function sodium_crypto_sign_secretkey;
use function sodium_crypto_sign_seed_keypair;
use function sodium_crypto_sign_verify_detached;
use function strlen;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SODIUM_CRYPTO_SIGN_BYTES;
use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
use const SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
use const SODIUM_CRYPTO_SIGN_SEEDBYTES;

/**
 * Optional Ed25519 signature of a backup's sidecar fields (1.29+, owner decision Q-O3: shipped, off by default).
 *
 * The signed message is `nowo-site-backup-signature-v1\n` + the canonical JSON (keys sorted) of exactly: id,
 * created_at, format, encrypted, encryption, key_id, sha256 (archive bytes), size, plaintext_sha256 and
 * checksums_sha256 (SHA-256 of the canonical per-file checksum map, so every archived file's content). It does NOT
 * cover content, table_rules, table_stats, label, created_by, extra_meta or warnings. It protects the sidecars that
 * retention and import trust, and unencrypted archives, against a bucket writer. With `backup.signing.required`
 * (default when a verification key is configured) an unsigned sidecar is refused (EO-M2).
 *
 * - `backup.signing.secret_key`: base64 of a 32-byte seed or a 64-byte secret key, from the environment (keys apart
 *   from the backups); never logged nor stored by the bundle.
 * - `backup.signing.public_keys`: key id => base64 public key, for verification (the own public key is added).
 */
final class BackupSigner
{
    private readonly ?string $secretKey;

    /** @var array<string, string> key id => raw public key */
    private readonly array $publicKeys;

    /**
     * @param array<string, string> $publicKeys key id => base64 public key
     */
    public function __construct(
        #[SensitiveParameter]
        ?string $secretKey = null,
        private readonly string $keyId = 'default',
        array $publicKeys = [],
        private readonly ?bool $required = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $keyId) !== 1) {
            throw new InvalidArgumentException('backup.signing.key_id must match [A-Za-z0-9_.-]{1,64}.');
        }

        $keys = [];
        foreach ($publicKeys as $id => $encoded) {
            $raw = base64_decode((string) $encoded, true);
            if (!is_string($id) || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('backup.signing.public_keys must map key ids to base64 Ed25519 public keys.');
            }
            $keys[$id] = $raw;
        }

        $secret = null;
        if ($secretKey !== null && $secretKey !== '') {
            self::assertSodium();
            $raw = base64_decode($secretKey, true);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                $raw = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
            }
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new InvalidArgumentException('backup.signing.secret_key must be base64 of a 32-byte seed or a 64-byte Ed25519 secret key.');
            }
            $secret       = $raw;
            $keys[$keyId] = sodium_crypto_sign_publickey_from_secretkey($raw);
        }

        $this->secretKey  = $secret;
        $this->publicKeys = $keys;
    }

    public function canSign(): bool
    {
        return $this->secretKey !== null;
    }

    public function canVerify(): bool
    {
        return $this->publicKeys !== [];
    }

    /**
     * EO-M2: whether an unsigned (or wrongly signed) sidecar must be refused. `backup.signing.required`; null = true
     * whenever a verification key is configured.
     */
    public function isRequired(): bool
    {
        return $this->required ?? $this->canVerify();
    }

    /**
     * @param array<string, scalar|null> $fields {@see self::fieldsFromArtifact()}
     *
     * @return array{signature: string, signing_key_id: string}
     */
    public function sign(array $fields): array
    {
        if ($this->secretKey === null) {
            throw new InvalidArgumentException('No signing key configured (backup.signing.secret_key).');
        }

        return [
            'signature'      => base64_encode(sodium_crypto_sign_detached(self::message($fields), $this->secretKey)),
            'signing_key_id' => $this->keyId,
        ];
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    public function verify(array $fields, string $signature, string $keyId): bool
    {
        $key = $this->publicKeys[$keyId] ?? null;
        $raw = base64_decode($signature, true);
        if ($key === null || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        self::assertSodium();

        return sodium_crypto_sign_verify_detached($raw, self::message($fields), $key);
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function fieldsFromArtifact(BackupArtifact $artifact): array
    {
        $meta = $artifact->getMeta();

        return [
            'id'               => $artifact->getId(),
            'created_at'       => $artifact->getCreatedAt()->format(DATE_ATOM),
            'format'           => $artifact->getFormat()->value,
            'encrypted'        => $artifact->isEncrypted(),
            'encryption'       => is_string($meta['encryption'] ?? null) ? $meta['encryption'] : null,
            'key_id'           => is_string($meta['key_id'] ?? null) ? $meta['key_id'] : null,
            'sha256'           => $artifact->getArchiveSha256(),
            'size'             => $artifact->getSizeBytes(),
            'plaintext_sha256' => $artifact->getPlaintextSha256(),
            'checksums_sha256' => self::checksumsDigest($artifact->getChecksums()),
        ];
    }

    /**
     * The same fields read from a remote `.backup.json` or a local `.meta.json` sidecar.
     *
     * @param array<string, mixed> $sidecar
     *
     * @return array<string, scalar|null>
     */
    public static function fieldsFromSidecar(array $sidecar): array
    {
        $meta = is_array($sidecar['meta'] ?? null) ? $sidecar['meta'] : $sidecar;
        $str  = static fn (mixed $v): ?string => is_string($v) ? $v : null;

        return [
            'id'               => $str($sidecar['id'] ?? null),
            'created_at'       => $str($sidecar['created_at'] ?? null),
            'format'           => $str($sidecar['format'] ?? ($meta['format'] ?? null)),
            'encrypted'        => is_bool($sidecar['encrypted'] ?? null) ? $sidecar['encrypted'] : (($meta['encrypted'] ?? false) === true),
            'encryption'       => $str($sidecar['encryption'] ?? ($meta['encryption'] ?? null)),
            'key_id'           => $str($sidecar['key_id'] ?? ($meta['key_id'] ?? null)),
            'sha256'           => $str($sidecar['sha256'] ?? ($sidecar['archive_sha256'] ?? null)),
            'size'             => is_int($sidecar['size'] ?? null) ? $sidecar['size'] : (is_int($sidecar['size_bytes'] ?? null) ? $sidecar['size_bytes'] : null),
            'plaintext_sha256' => $str($sidecar['plaintext_sha256'] ?? ($meta['plaintext_sha256'] ?? null)),
            'checksums_sha256' => $str($sidecar['checksums_sha256'] ?? ($meta['checksums_sha256'] ?? null)),
        ];
    }

    /**
     * @param array<string, string> $checksums
     */
    public static function checksumsDigest(array $checksums): string
    {
        ksort($checksums);

        return hash('sha256', json_encode($checksums, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{secret_key: string, public_key: string} base64 seed and public key
     */
    public static function generateKeyPair(): array
    {
        self::assertSodium();
        $seed    = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $keyPair = sodium_crypto_sign_seed_keypair($seed);

        return ['secret_key' => base64_encode($seed), 'public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair))];
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    private static function message(array $fields): string
    {
        ksort($fields);

        return 'nowo-site-backup-signature-v1' . "\n" . json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function assertSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new InvalidArgumentException('Backup signatures need ext-sodium.');
        }
    }
}
