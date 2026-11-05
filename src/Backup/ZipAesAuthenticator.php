<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use SensitiveParameter;

use function fclose;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function hash_equals;
use function hash_final;
use function hash_init;
use function hash_pbkdf2;
use function hash_update;
use function max;
use function min;
use function ord;
use function strlen;
use function strrpos;
use function substr;
use function unpack;

use const HASH_HMAC;
use const SEEK_END;
use const SEEK_SET;

/**
 * Checks the WinZip AE-1 / AE-2 authentication code (HMAC-SHA1, 10 bytes) of every AES-256 entry of a zip, in PHP.
 *
 * `ZipArchive::extractTo()` on PHP < 8.4 ignores libzip's read errors, so an entry whose ciphertext was modified is
 * extracted (as altered plaintext) and reported as success: the HMAC that AES zips carry was never enforced. This
 * class verifies it independently of the PHP / libzip versions, before anything is extracted.
 *
 * Key derivation (WinZip AE): PBKDF2-HMAC-SHA1(password, salt, 1000 iterations) → 32-byte AES key, 32-byte HMAC key,
 * 2-byte password verifier. The HMAC covers the ciphertext between the verifier and the authentication code.
 */
final class ZipAesAuthenticator
{
    private const AES_256_STRENGTH = 3;
    private const SALT_LENGTH      = 16;
    private const KEY_LENGTH       = 32;
    private const VERIFIER_LENGTH  = 2;
    private const MAC_LENGTH       = 10;
    private const ITERATIONS       = 1000;
    private const CHUNK            = 1 << 20;
    private const EOCD_MAX_SEARCH  = 65_557;

    /**
     * @throws CorruptArchiveException with code CHECKSUM when an entry fails its authentication code or the zip
     *                                 structure cannot be read
     * @throws WrongPasswordException when the password verifier does not match
     */
    public static function verify(string $path, #[SensitiveParameter] ArchivePassword $password): void
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new CorruptArchiveException('zip authentication: unable to open the archive.', CorruptArchiveException::CHECKSUM);
        }

        try {
            foreach (self::aesEntries($handle) as $entry) {
                self::verifyEntry($handle, $entry, $password);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<array{name: string, offset: int, size: int}>
     */
    private static function aesEntries($handle): array
    {
        [$count, $cdOffset, $cdSize] = self::centralDirectory($handle);
        $cd                          = self::readAt($handle, $cdOffset, $cdSize);
        $entries                     = [];
        $at                          = 0;
        for ($i = 0; $i < $count; ++$i) {
            if (substr($cd, $at, 4) !== "PK\x01\x02" || strlen($cd) < $at + 46) {
                throw self::corrupt('bad central directory entry');
            }
            /** @var array{method: int, comp: int, uncomp: int, n: int, e: int, c: int, offset: int} $h */
            $h     = unpack('vmethod', $cd, $at + 10) + unpack('Vcomp/Vuncomp/vn/ve/vc', $cd, $at + 20) + unpack('Voffset', $cd, $at + 42);
            $name  = substr($cd, $at + 46, $h['n']);
            $extra = substr($cd, $at + 46 + $h['n'], $h['e']);
            $size  = $h['comp'];
            $off   = $h['offset'];
            if ($size === 0xFFFFFFFF || $off === 0xFFFFFFFF || $h['uncomp'] === 0xFFFFFFFF) {
                [$size, $off] = self::zip64Values($extra, $h['uncomp'] === 0xFFFFFFFF, $size, $off);
            }
            if ($h['method'] === 99) {
                if (self::aesStrength($extra) !== self::AES_256_STRENGTH) {
                    throw self::corrupt('only AES-256 entries are accepted');
                }
                $entries[] = ['name' => $name, 'offset' => $off, 'size' => $size];
            }
            $at += 46 + $h['n'] + $h['e'] + $h['c'];
        }

        return $entries;
    }

    /**
     * @param resource $handle
     * @param array{name: string, offset: int, size: int} $entry
     */
    private static function verifyEntry($handle, array $entry, #[SensitiveParameter] ArchivePassword $password): void
    {
        $header = self::readAt($handle, $entry['offset'], 30);
        if (substr($header, 0, 4) !== "PK\x03\x04") {
            throw self::corrupt('bad local header');
        }
        /** @var array{n: int, e: int} $lengths */
        $lengths    = unpack('vn/ve', $header, 26);
        $data       = $entry['offset'] + 30 + $lengths['n'] + $lengths['e'];
        $cipherSize = $entry['size'] - self::SALT_LENGTH - self::VERIFIER_LENGTH - self::MAC_LENGTH;
        if ($cipherSize < 0) {
            throw self::corrupt('entry too short');
        }

        $prefix = self::readAt($handle, $data, self::SALT_LENGTH + self::VERIFIER_LENGTH);
        $keys   = hash_pbkdf2('sha1', $password->reveal(), substr($prefix, 0, self::SALT_LENGTH), self::ITERATIONS, 2 * self::KEY_LENGTH + self::VERIFIER_LENGTH, true);
        if (!hash_equals(substr($keys, 2 * self::KEY_LENGTH), substr($prefix, self::SALT_LENGTH))) {
            throw new WrongPasswordException('Unable to decrypt the archive: wrong password.');
        }

        $mac  = hash_init('sha1', HASH_HMAC, substr($keys, self::KEY_LENGTH, self::KEY_LENGTH));
        $left = $cipherSize;
        while ($left > 0) {
            $chunk = fread($handle, (int) min(self::CHUNK, $left));
            if ($chunk === false || $chunk === '') {
                throw self::corrupt('truncated entry');
            }
            hash_update($mac, $chunk);
            $left -= strlen($chunk);
        }
        $stored = (string) fread($handle, self::MAC_LENGTH);
        if (!hash_equals(substr(hash_final($mac, true), 0, self::MAC_LENGTH), $stored)) {
            throw new CorruptArchiveException('The encrypted archive failed its integrity check (tampered or truncated).', CorruptArchiveException::CHECKSUM);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array{0: int, 1: int, 2: int} entry count, central directory offset and size
     */
    private static function centralDirectory($handle): array
    {
        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw self::corrupt('unseekable archive');
        }
        $fileSize = (int) ftell($handle);
        $tailSize = (int) min($fileSize, self::EOCD_MAX_SEARCH);
        $tail     = self::readAt($handle, $fileSize - $tailSize, $tailSize);
        $eocd     = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) < $eocd + 22) {
            throw self::corrupt('no end of central directory');
        }
        /** @var array{count: int, size: int, offset: int} $end */
        $end = unpack('vcount/Vsize/Voffset', $tail, $eocd + 10);
        if ($end['count'] !== 0xFFFF && $end['size'] !== 0xFFFFFFFF && $end['offset'] !== 0xFFFFFFFF) {
            return [$end['count'], $end['offset'], $end['size']];
        }

        // ZIP64: the locator sits right before the classic record.
        $locatorAt = $fileSize - $tailSize + $eocd - 20;
        $locator   = self::readAt($handle, max(0, $locatorAt), 20);
        if (substr($locator, 0, 4) !== "PK\x06\x07") {
            throw self::corrupt('no zip64 locator');
        }
        $record = self::readAt($handle, self::uint64($locator, 8), 56);
        if (substr($record, 0, 4) !== "PK\x06\x06") {
            throw self::corrupt('no zip64 end of central directory');
        }

        return [self::uint64($record, 32), self::uint64($record, 48), self::uint64($record, 40)];
    }

    /**
     * @return array{0: int, 1: int} compressed size and local header offset from the ZIP64 extra field
     */
    private static function zip64Values(string $extra, bool $hasUncompressed, int $size, int $offset): array
    {
        $field = self::extraField($extra, 0x0001);
        if ($field === null) {
            throw self::corrupt('missing zip64 extra field');
        }
        $at = $hasUncompressed ? 8 : 0;
        if ($size === 0xFFFFFFFF) {
            $size = self::uint64($field, $at);
            $at += 8;
        }
        if ($offset === 0xFFFFFFFF) {
            $offset = self::uint64($field, $at);
        }

        return [$size, $offset];
    }

    private static function aesStrength(string $extra): ?int
    {
        $field = self::extraField($extra, 0x9901);
        if ($field === null || strlen($field) < 7 || substr($field, 2, 2) !== 'AE') {
            return null;
        }

        return ord($field[4]);
    }

    private static function extraField(string $extra, int $id): ?string
    {
        $at = 0;
        while ($at + 4 <= strlen($extra)) {
            /** @var array{id: int, len: int} $h */
            $h = unpack('vid/vlen', $extra, $at);
            if ($h['id'] === $id) {
                return substr($extra, $at + 4, $h['len']);
            }
            $at += 4 + $h['len'];
        }

        return null;
    }

    private static function uint64(string $bytes, int $at): int
    {
        if (strlen($bytes) < $at + 8) {
            throw self::corrupt('short zip64 field');
        }
        /** @var array{lo: int, hi: int} $v */
        $v = unpack('Vlo/Vhi', $bytes, $at);
        if ($v['hi'] > 0x7FFFFFFF) {
            throw self::corrupt('zip64 value out of range');
        }

        return ($v['hi'] << 32) | $v['lo'];
    }

    /**
     * @param resource $handle
     */
    private static function readAt($handle, int $offset, int $length): string
    {
        if ($offset < 0 || fseek($handle, $offset, SEEK_SET) !== 0) {
            throw self::corrupt('offset outside the archive');
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, max(1, min(self::CHUNK, $length - strlen($data))));
            if ($chunk === false || $chunk === '') {
                throw self::corrupt('truncated archive');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function corrupt(string $reason): CorruptArchiveException
    {
        return new CorruptArchiveException('The encrypted archive failed its integrity check (' . $reason . ').', CorruptArchiveException::CHECKSUM);
    }
}
