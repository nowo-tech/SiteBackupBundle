<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Exception;

use RuntimeException;

/**
 * An archive to import is not a supported format, is unsafe (path escape, symlink, legacy ZipCrypto) or fails its
 * checksums (1.29+).
 */
final class CorruptArchiveException extends RuntimeException implements SiteBackupException
{
    /** Exception code: a MANIFEST checksum did not match (integrity) rather than an unsupported / unsafe archive. */
    public const CHECKSUM = 1;

    /** Exception code: the archive is not (fully) encrypted although a password was given (EO-M1 downgrade). */
    public const NOT_ENCRYPTED = 2;

    /** Exception code: a required signature is missing or invalid (EO-M2). */
    public const SIGNATURE = 3;
}
