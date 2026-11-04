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
}
