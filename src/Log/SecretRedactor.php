<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Log;

use function preg_replace;

/**
 * Masks credentials that may appear in subprocess / driver error messages before they are logged (REQ-OBS-001).
 *
 * - URL user info: `mysql://app:secret@db/app` → `mysql://app:***@db/app`;
 * - `password=…`, `pwd=…`, `MYSQL_PWD=…`, `-p…` / `--password=…` CLI options.
 *
 * Stateless: static helpers only.
 */
final class SecretRedactor
{
    public const MASK = '***';

    public static function redact(string $message): string
    {
        $message = (string) preg_replace('#(://[^:/@\s]*):[^@\s]*@#', '$1:' . self::MASK . '@', $message);
        $message = (string) preg_replace('#\b((?:MYSQL_PWD|password|passwd|pwd)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&;,]+)#i', '$1' . self::MASK, $message);

        return (string) preg_replace('#(\s--password=|\s-p)(?!\s)\S+#', '$1' . self::MASK, $message);
    }
}
