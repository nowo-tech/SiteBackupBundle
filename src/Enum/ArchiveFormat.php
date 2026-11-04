<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Enum;

use function str_ends_with;
use function substr;

/**
 * Archive container of a backup (1.28+).
 *
 * - {@see self::TarGz}: files + `database/dump.sql` + `MANIFEST.json` (the 1.27 layout).
 * - {@see self::Zip}: the same tree in a zip (needs ext-zip).
 * - {@see self::SqlGz}: the database dump only, gzip-compressed; no MANIFEST (its checksum is in the sidecar).
 */
enum ArchiveFormat: string
{
    case TarGz = 'tar.gz';
    case Zip   = 'zip';
    case SqlGz = 'sql.gz';

    /**
     * File name extension, without the leading dot.
     */
    public function extension(): string
    {
        return $this->value;
    }

    /**
     * Format of an archive file name; 1.27 archives are always `tar.gz`.
     */
    public static function fromFilename(string $filename): self
    {
        if (str_ends_with($filename, '.age')) {
            $filename = substr($filename, 0, -4);
        }

        foreach ([self::SqlGz, self::Zip, self::TarGz] as $format) {
            if (str_ends_with($filename, '.' . $format->value)) {
                return $format;
            }
        }

        return self::TarGz;
    }

    public function hasManifest(): bool
    {
        return $this !== self::SqlGz;
    }
}
