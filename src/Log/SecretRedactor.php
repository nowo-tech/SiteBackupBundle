<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Log;

use function preg_replace;

/**
 * Masks credentials that may appear in subprocess / driver error messages before they are logged (REQ-OBS-001).
 *
 * - URL user info: `mysql://app:secret@db/app` → `mysql://app:***@db/app`;
 * - `password=…`, `pwd=…`, `MYSQL_PWD=…`, `-p…` / `--password=…` CLI options;
 * - signed URL / token query parameters (`X-Amz-Signature=…`, `X-Amz-Credential=…`, `token=…`, …) (1.29+).
 * - 1.28+: `"password": "…"` (any key with pass / secret / token / key), `IDENTIFIED BY '…'`, `*_SECRET=` / `*_KEY=` /
 *   `*_TOKEN=` env assignments, `token: …`, `Bearer …` / `Basic …`.
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

        // Signed URL / bearer parameters (S3 pre-signed URLs, tokens) that a remote client may quote (1.29+).
        $message = (string) preg_replace('#\b((?:X-Amz-(?:Signature|Credential|Security-Token)|Signature|sig|token|access_token|api_key)=)[^\s&"\']+#i', '$1' . self::MASK, $message);
        // EN-L5: JSON keys, SQL grants, env assignments, YAML-ish keys and bearer tokens.
        $message = (string) preg_replace('#("[A-Za-z0-9_.-]*(?:pass|secret|token|key|pwd|credential)[A-Za-z0-9_.-]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"#i', '$1"' . self::MASK . '"', $message);
        $message = (string) preg_replace('#(IDENTIFIED\s+(?:WITH\s+\S+\s+)?BY\s+)(\'[^\']*\'|"[^"]*")#i', '$1\'' . self::MASK . '\'', $message);
        $message = (string) preg_replace('#\b([A-Z0-9_]*(?:SECRET|TOKEN|KEY|PASSWORD|PASSWD|PWD|CREDENTIALS?)[A-Z0-9_]*\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s&;,]+)#', '$1' . self::MASK, $message);
        $message = (string) preg_replace('#\b((?:token|secret|api[_-]?key|access[_-]?key|client[_-]?secret)\s*:\s*)("[^"]*"|\'[^\']*\'|\S+)#i', '$1' . self::MASK, $message);
        $message = (string) preg_replace('#\b(Bearer)\s+[A-Za-z0-9._~+/=-]+#i', '$1 ' . self::MASK, $message);
        $message = (string) preg_replace('#\b(Basic)\s+[A-Za-z0-9+/]{8,}={0,2}#', '$1 ' . self::MASK, $message);

        return (string) preg_replace('#(\s--password=|\s-p)(?!\s)\S+#', '$1' . self::MASK, $message);
    }
}
