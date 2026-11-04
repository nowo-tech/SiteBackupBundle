<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Crypto;

use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use SensitiveParameter;

/**
 * Passphrase container for `tar.gz` / `sql.gz` archives (1.29+). The default implementation is
 * {@see AgeBinaryEncryptor} (age ≥ 1.3 + age-plugin-batchpass, owner decision Q-O4 option A).
 */
interface ArchiveEncryptorInterface
{
    /** `meta.encryption` value of the archives this encryptor writes. */
    public function name(): string;

    /** File name suffix of an encrypted archive, with the dot (e.g. `.age`). */
    public function suffix(): string;

    /** True when $header (the first bytes of a file) is one of its containers. */
    public function recognises(string $header): bool;

    /**
     * @throws FormatUnavailableException when the encryptor cannot run (missing / too old binary, missing plugin)
     */
    public function assertAvailable(): void;

    /**
     * @param (callable(): void)|null $tick called while the encryption runs
     *
     * @throws FormatUnavailableException
     */
    public function encrypt(string $plainFile, string $encryptedFile, #[SensitiveParameter] ArchivePassword $password, ?callable $tick = null): void;

    /**
     * @param (callable(): void)|null $tick called while the decryption runs
     *
     * @throws FormatUnavailableException|WrongPasswordException
     */
    public function decrypt(string $encryptedFile, string $plainFile, #[SensitiveParameter] ArchivePassword $password, ?callable $tick = null): void;
}
