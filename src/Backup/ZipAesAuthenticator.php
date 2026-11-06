<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use SensitiveParameter;
use ZipArchive;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function hash_equals;
use function hash_final;
use function hash_init;
use function hash_pbkdf2;
use function hash_update;
use function is_resource;
use function max;
use function min;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function unpack;

use const HASH_HMAC;
use const SEEK_END;
use const SEEK_SET;

/**
 * Checks the WinZip AE-1 / AE-2 authentication code (HMAC-SHA1, 10 bytes) of every entry of an AES-256 zip, in PHP.
 *
 * `ZipArchive::extractTo()` on PHP < 8.4 ignores libzip's read errors, and a zip stream stops before libzip's own
 * check, so the HMAC is verified here, independently of the PHP / libzip versions, before anything is extracted.
 *
 * The entries and their raw bytes come from libzip itself (`statIndex()`, `getStreamIndex()` with `FL_ENCRYPTED`), not
 * from a parser of our own: what is authenticated is exactly what libzip extracts. Every entry must be AES-256, and an
 * archive with no entries is refused, so passing always means that every entry was authenticated. An archive with more
 * than one end of central directory record is refused too: tools can disagree on which one counts.
 *
 * One exception: libzip hands out nothing for an entry that declares size 0, stored bytes or not, so those are read at
 * the offset in the central directory, which must match libzip's view (count, names, compressed sizes). PHP does not
 * expose libzip's offsets; with a single end record there is only one directory for both to read.
 *
 * Key derivation (WinZip AE): PBKDF2-HMAC-SHA1(password, salt, 1000 iterations) → 32-byte AES key, 32-byte HMAC key,
 * 2-byte password verifier. The HMAC covers the ciphertext between the verifier and the authentication code. It does
 * not cover the names, sizes or other metadata of the central directory.
 */
final class ZipAesAuthenticator
{
    private const SALT_LENGTH     = 16;
    private const KEY_LENGTH      = 32;
    private const VERIFIER_LENGTH = 2;
    private const MAC_LENGTH      = 10;
    private const ITERATIONS      = 1000;
    private const CHUNK           = 1 << 20;
    private const END_RECORD      = "PK\x05\x06";
    private const END_LENGTH      = 22;

    /** Wider than what any zip reader searches (a 64 KiB comment, the record, the ZIP64 locator). */
    private const END_SCAN_BYTES = 1 << 17;

    private const NO_VALUE_32 = 0xFFFFFFFF;

    /**
     * @param ZipArchive|null $open the handle that will extract, when there is one (read through it, not the path again)
     *
     * @throws CorruptArchiveException with code CHECKSUM when an entry fails its authentication code, is not AES-256,
     *                                 or the zip structure is ambiguous or unreadable
     * @throws WrongPasswordException when the password verifier does not match
     */
    public static function verify(string $path, #[SensitiveParameter] ArchivePassword $password, ?ZipArchive $open = null): void
    {
        self::assertSingleEndRecord($path);

        $zip = $open ?? new ZipArchive();
        if (!$open instanceof ZipArchive && $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw self::corrupt('unable to open the archive');
        }

        try {
            if ($zip->numFiles < 1) {
                throw self::corrupt('no entries');
            }
            $directory = null;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw self::corrupt('unreadable entry');
                }
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_AES_256) {
                    throw self::corrupt('only AES-256 entries are accepted');
                }
                if ((int) $stat['size'] > 0) {
                    $stream = @$zip->getStreamIndex($i, ZipArchive::FL_ENCRYPTED);
                } else {
                    $directory ??= self::directory($path, $zip);
                    $stream = self::openEmptyEntry($path, $directory[$i]);
                }
                if (!is_resource($stream)) {
                    throw self::corrupt('unreadable entry');
                }
                self::authenticate($stream, (int) $stat['comp_size'], $password);
            }
        } finally {
            if (!$open instanceof ZipArchive) {
                $zip->close();
            }
        }
    }

    /**
     * @param resource $stream at the salt of the entry; closed here
     */
    private static function authenticate($stream, int $storedSize, #[SensitiveParameter] ArchivePassword $password): void
    {
        try {
            $cipherSize = $storedSize - self::SALT_LENGTH - self::VERIFIER_LENGTH - self::MAC_LENGTH;
            if ($cipherSize < 0) {
                throw self::corrupt('entry too short');
            }
            $prefix = self::read($stream, self::SALT_LENGTH + self::VERIFIER_LENGTH);
            $keys   = hash_pbkdf2('sha1', $password->reveal(), substr($prefix, 0, self::SALT_LENGTH), self::ITERATIONS, 2 * self::KEY_LENGTH + self::VERIFIER_LENGTH, true);
            if (!hash_equals(substr($keys, 2 * self::KEY_LENGTH), substr($prefix, self::SALT_LENGTH))) {
                throw new WrongPasswordException('Unable to decrypt the archive: wrong password.');
            }

            $mac = hash_init('sha1', HASH_HMAC, substr($keys, self::KEY_LENGTH, self::KEY_LENGTH));
            for ($left = $cipherSize; $left > 0; $left -= self::CHUNK) {
                hash_update($mac, self::read($stream, (int) min(self::CHUNK, $left)));
            }
            $stored = self::read($stream, self::MAC_LENGTH);
        } finally {
            fclose($stream);
        }

        if (!hash_equals(substr(hash_final($mac, true), 0, self::MAC_LENGTH), $stored)) {
            throw new CorruptArchiveException('The encrypted archive failed its integrity check (tampered or truncated).', CorruptArchiveException::CHECKSUM);
        }
    }

    /**
     * @param array{name: string, offset: int} $entry
     *
     * @return resource at the salt of the entry
     */
    private static function openEmptyEntry(string $path, array $entry)
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw self::corrupt('unable to open the archive');
        }
        $header = self::readAt($handle, $entry['offset'], 30);
        if (substr($header, 0, 4) !== "PK\x03\x04") {
            fclose($handle);

            throw self::corrupt('bad local header');
        }
        /** @var array{n: int, e: int} $lengths */
        $lengths = unpack('vn/ve', $header, 26);
        fseek($handle, $entry['offset'] + 30 + $lengths['n'] + $lengths['e'], SEEK_SET);

        return $handle;
    }

    /**
     * The central directory, checked against what libzip read.
     *
     * @return list<array{name: string, offset: int}> by index
     */
    private static function directory(string $path, ZipArchive $zip): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw self::corrupt('unable to open the archive');
        }

        try {
            [$count, $cdOffset, $cdSize] = self::centralDirectory($handle);
            if ($count !== $zip->numFiles) {
                throw self::corrupt('the central directory does not match');
            }
            $cd      = self::readAt($handle, $cdOffset, $cdSize);
            $entries = [];
            $at      = 0;
            for ($i = 0; $i < $count; ++$i) {
                if (substr($cd, $at, 4) !== "PK\x01\x02" || strlen($cd) < $at + 46) {
                    throw self::corrupt('bad central directory entry');
                }
                /** @var array{comp: int, uncomp: int, n: int, e: int, c: int, offset: int} $h */
                $h               = unpack('Vcomp/Vuncomp/vn/ve/vc', $cd, $at + 20) + unpack('Voffset', $cd, $at + 42);
                $name            = substr($cd, $at + 46, $h['n']);
                $extra           = substr($cd, $at + 46 + $h['n'], $h['e']);
                [$size, $offset] = self::zip64Values($extra, $h['uncomp'] === self::NO_VALUE_32, $h['comp'], $h['offset']);
                $stat            = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
                if ($stat === false || $stat['name'] !== $name || (int) $stat['comp_size'] !== $size) {
                    throw self::corrupt('the central directory does not match');
                }
                $entries[] = ['name' => $name, 'offset' => $offset];
                $at += 46 + $h['n'] + $h['e'] + $h['c'];
            }

            return $entries;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array{0: int, 1: int, 2: int} entry count, central directory offset and size
     */
    private static function centralDirectory($handle): array
    {
        fseek($handle, 0, SEEK_END);
        $fileSize = (int) ftell($handle);
        $tailSize = (int) min($fileSize, self::END_SCAN_BYTES);
        $tail     = self::readAt($handle, $fileSize - $tailSize, $tailSize);
        $eocd     = (int) strrpos($tail, self::END_RECORD);
        /** @var array{count: int, size: int, offset: int} $end */
        $end = unpack('vcount/Vsize/Voffset', $tail, $eocd + 10);
        if ($end['count'] !== 0xFFFF && $end['size'] !== self::NO_VALUE_32 && $end['offset'] !== self::NO_VALUE_32) {
            return [$end['count'], $end['offset'], $end['size']];
        }

        // ZIP64: the locator sits right before the classic record.
        $locator = self::readAt($handle, max(0, $fileSize - $tailSize + $eocd - 20), 20);
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
     * @return array{0: int, 1: int} compressed size and local header offset, from the ZIP64 extra field when needed
     */
    private static function zip64Values(string $extra, bool $hasUncompressed, int $size, int $offset): array
    {
        if ($size !== self::NO_VALUE_32 && $offset !== self::NO_VALUE_32 && !$hasUncompressed) {
            return [$size, $offset];
        }
        $field = self::extraField($extra, 0x0001);
        if ($field === null) {
            throw self::corrupt('missing zip64 extra field');
        }
        $at = $hasUncompressed ? 8 : 0;
        if ($size === self::NO_VALUE_32) {
            $size = self::uint64($field, $at);
            $at += 8;
        }
        if ($offset === self::NO_VALUE_32) {
            $offset = self::uint64($field, $at);
        }

        return [$size, $offset];
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
        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            throw self::corrupt('offset outside the archive');
        }

        return self::read($handle, $length);
    }

    /**
     * Exactly one end record, that closes the file, with no other record in its comment. Readers differ on which record
     * they take when there are several, so a second one is never accepted, whatever libzip makes of it.
     */
    private static function assertSingleEndRecord(string $path): void
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw self::corrupt('unable to open the archive');
        }

        try {
            $size  = (int) @filesize($path);
            $start = (int) max(0, $size - self::END_SCAN_BYTES);
            fseek($handle, $start, SEEK_SET);
            $tail = (string) fread($handle, self::END_SCAN_BYTES);
        } finally {
            fclose($handle);
        }

        $end = null;
        for ($at = strpos($tail, self::END_RECORD); $at !== false; $at = strpos($tail, self::END_RECORD, $at + 1)) {
            if (!self::isPlausibleEndRecord($tail, $at, $start + $at)) {
                continue;
            }
            if ($end !== null) {
                throw self::corrupt('more than one end of central directory record');
            }
            $end = $at;
        }
        if ($end === null) {
            throw self::corrupt('no end of central directory');
        }

        /** @var array{length: int} $comment */
        $comment = unpack('vlength', $tail, $end + self::END_LENGTH - 2);
        if ($end + self::END_LENGTH + $comment['length'] !== strlen($tail)) {
            throw self::corrupt('data after the end of central directory record');
        }
        // Not plausible as a record, but another reader may think otherwise.
        if (strpos($tail, self::END_RECORD, $end + 4) !== false) {
            throw self::corrupt('more than one end of central directory record');
        }
    }

    /**
     * A record some reader could accept. Ciphertext that happens to hold the signature fails this.
     */
    private static function isPlausibleEndRecord(string $tail, int $at, int $position): bool
    {
        if (strlen($tail) < $at + self::END_LENGTH) {
            return false;
        }
        /** @var array{disk: int, cdDisk: int, here: int, total: int, size: int, offset: int} $record */
        $record = unpack('vdisk/vcdDisk/vhere/vtotal/Vsize/Voffset', $tail, $at + 4);
        $zip64  = $record['size'] === self::NO_VALUE_32 || $record['offset'] === self::NO_VALUE_32;

        return $record['disk'] === 0 && $record['cdDisk'] === 0 && $record['here'] === $record['total']
            && ($zip64 || $position >= $record['offset'] + $record['size']);
    }

    /**
     * @param resource $stream
     */
    private static function read($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            // A read error is only a warning on a zip stream.
            error_clear_last();
            $chunk = @fread($stream, max(1, $length - strlen($data)));
            if ($chunk === false || $chunk === '' || error_get_last() !== null) {
                throw self::corrupt('truncated entry');
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
