<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Log;

use Throwable;

use function mb_scrub;
use function mb_substr;
use function preg_replace;

/**
 * Masks credentials that may appear in subprocess / driver error messages before they are logged (REQ-OBS-001).
 *
 * - URL user info: `mysql://app:secret@db/app` → `mysql://app:***@db/app`;
 * - `password=…`, `pwd=…`, `MYSQL_PWD=…`, `-p…` / `--password=…` CLI options;
 * - signed URL / token query parameters (`X-Amz-Signature=…`, `X-Amz-Credential=…`, `token=…`, …) (1.29+).
 * - 1.28+: `"password": "…"` (any key with pass / secret / token / key), `IDENTIFIED BY '…'`, `*_SECRET=` / `*_KEY=` /
 *   `*_TOKEN=` env assignments, `token: …`, `Bearer …` / `Basic …`.
 * - 1.34+: SQL `PASSWORD '…'` / `PASSPHRASE "…"` / `IDENTIFIED BY|AS '…'` literals quoted by a failing statement;
 *   quoted values honour backslash escapes and doubled quotes; a URL password containing a raw `@` is masked up to the
 *   last `@` of the token; `--password <value>`, quoted argv (`'-pX'`, `'--password=a b'`), case-insensitive
 *   `*secret*=` / `*key*=` / `*token*=` / `*password*=` keys (env, camelCase), YAML `*_secret:`, XML elements
 *   (`<SecretAccessKey>`), ODBC `Pwd={…}` and age identities. Only the exact MySQL hint `(using password: YES)` /
 *   `(… NO)` is left readable.
 *
 * {@see self::forStorage()} / {@see self::fromThrowable()} also cap the length: use them for anything that is
 * persisted, dispatched or rendered (history, restore / setup progress, events, panel messages).
 *
 * Stateless: static helpers only.
 */
final class SecretRedactor
{
    public const MASK = '***';

    /** Default length cap of {@see self::forStorage()} (same as `BackupFailedEvent`). */
    public const STORAGE_MAX_LENGTH = 500;

    /** A single- or double-quoted value with backslash escapes (and SQL doubled single quotes). */
    private const QUOTED = <<<'RE'
        "(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.|'')*'
        RE;

    /**
     * Redacted and capped message, safe to persist, dispatch, render or JSON-encode (1.34+): invalid UTF-8 bytes are
     * replaced first (a latin1 snippet in a driver error must not make the history / progress write fail).
     */
    public static function forStorage(string $message, int $maxLength = self::STORAGE_MAX_LENGTH): string
    {
        return mb_substr(self::redact(mb_scrub($message, 'UTF-8')), 0, $maxLength, 'UTF-8');
    }

    /**
     * {@see self::forStorage()} of a throwable's message (1.34+). The trace and previous exceptions are never included.
     */
    public static function fromThrowable(Throwable $e, int $maxLength = self::STORAGE_MAX_LENGTH): string
    {
        return self::forStorage($e->getMessage(), $maxLength);
    }

    public static function redact(string $message): string
    {
        $q    = self::QUOTED;
        $mask = self::MASK;

        // Quoted argv of a Process command line: `'-p' 'X'`, `'-pX'`, `'--password=a b'` (1.34+).
        $message = (string) preg_replace('~\'(-p|--password)\'\s+\'(?:[^\'\\\\]|\\\\.)*\'~', '\'$1\' \'' . $mask . '\'', $message);
        $message = (string) preg_replace('~\'(-p|--password=)(?:[^\'\\\\]|\\\\.)+\'~', '\'$1' . $mask . '\'', $message);
        // URL user info, up to the LAST `@` of the token (a raw `@` inside the password, 1.34+).
        $message = (string) preg_replace('~(://[^:/@\s]*):\S*@~', '$1:' . $mask . '@', $message);
        // key=value / key: value; only the exact MySQL hint `(using password: YES|NO)` stays readable.
        $message = (string) preg_replace('~\b((?:MYSQL_PWD|password|passwd|pwd|passphrase)\s*[=:]\s*)(?!(?<=\(using password: )(?:YES|NO)\))(' . $q . '|\{(?:[^}]|\}\})*\}|[^\s&;,]+)~i', '$1' . $mask, $message);
        $message = (string) preg_replace('~((?:^|\s)--password\s+)(?![-\'"\s])\S+~', '$1' . $mask, $message);
        // SQL literals in a failing statement (`ALTER USER … PASSWORD 'x'`, `IDENTIFIED BY 'x'`, `… AS '*hash'`).
        $message = (string) preg_replace('~\b((?:PASSWORD|PASSPHRASE|IDENTIFIED\s+(?:WITH\s+\S+\s+)?(?:BY|AS))\s+)(' . $q . ')~i', '$1\'' . $mask . '\'', $message);

        // Signed URL / bearer parameters (S3 pre-signed URLs, tokens) that a remote client may quote (1.29+).
        $message = (string) preg_replace('~\b((?:X-Amz-(?:Signature|Credential|Security-Token)|Signature|sig|token|access_token|api_key)=)[^\s&"\']+~i', '$1' . $mask, $message);
        // EN-L5: JSON keys, env assignments (any case, 1.34+), YAML-ish keys, XML elements and bearer tokens.
        $message = (string) preg_replace('~("[A-Za-z0-9_.-]*(?:pass|secret|token|key|pwd|credential)[A-Za-z0-9_.-]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"~i', '$1"' . $mask . '"', $message);
        $message = (string) preg_replace('~\b([A-Za-z0-9_.-]*(?:SECRET|TOKEN|KEY|PASSWORD|PASSWD|PWD|PASSPHRASE|CREDENTIALS?)[A-Za-z0-9_.-]*\s*=\s*)(' . $q . '|[^\s&;,]+)~i', '$1' . $mask, $message);
        $message = (string) preg_replace('~\b([A-Za-z0-9_-]*(?:secret|token|api[_-]?key|access[_-]?key(?:[_-]?id)?|passphrase)[A-Za-z0-9_-]*\s*:\s*)(' . $q . '|\S+)~i', '$1' . $mask, $message);
        $message = (string) preg_replace('~<([A-Za-z0-9_:.-]*(?:secret|password|passphrase|token|credential)[A-Za-z0-9_:.-]*)>[^<]*</\1>~i', '<$1>' . $mask . '</$1>', $message);
        $message = (string) preg_replace('~\bAGE-SECRET-KEY-1[0-9A-Z]+~', 'AGE-SECRET-KEY-1' . $mask, $message);
        $message = (string) preg_replace('#\b(Bearer)\s+[A-Za-z0-9._~+/=-]+#i', '$1 ' . $mask, $message);
        $message = (string) preg_replace('~\b(Basic)\s+[A-Za-z0-9+/]{8,}={0,2}~', '$1 ' . $mask, $message);

        return (string) preg_replace('~((?:^|\s)--password=|(?:^|\s)-p)(?![\s\'"])\S+~', '$1' . $mask, $message);
    }
}
