<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use RuntimeException;
use Throwable;

use function bin2hex;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rename;
use function sprintf;
use function unlink;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * "New full after restore" (1.36+, Beacon): every restore writes the server's binary log (the dump import, the replay,
 * the files are not involved but a restore is one operation), so a chain whose last capture ends before a restore can
 * never be continued: its next incremental would carry the restore's own writes. Before a restore touches anything,
 * the manager records its instant in `<storage_dir>/.nowo-site-backup-restored.json`; an incremental of a parent whose
 * capture ended at or before that instant is refused like a gap ({@see BinlogGapException::CLOSED_BY_RESTORE}).
 *
 * Stateless helpers (no service state; safe in worker mode).
 */
final class RestoreMarker
{
    public const FILENAME = '.nowo-site-backup-restored.json';

    /**
     * Records a restore (keeps the latest instant). Throws when the marker cannot be written: the caller must not
     * restore then.
     */
    public static function write(string $path, DateTimeImmutable $at, string $kind, string $backupId): void
    {
        $previous = self::read($path);
        if ($previous instanceof DateTimeImmutable && $previous > $at) {
            $at = $previous;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new RuntimeException('The backup storage directory does not exist: the restore marker cannot be written.');
        }
        $tmp = $dir . '/.nowo-site-backup-restored.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $json = json_encode(['restored_at' => $at->format(DATE_ATOM), 'kind' => $kind, 'backup_id' => $backupId], JSON_THROW_ON_ERROR);
            if (@file_put_contents($tmp, $json . "\n") === false || !@chmod($tmp, 0o600) || !@rename($tmp, $path)) {
                throw new RuntimeException('The restore marker cannot be written; the restore was not started.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * The instant of the latest restore, null when none was recorded. A malformed marker counts as a restore at the
     * marker's modification time (fail closed, and a later restore or full backup moves past it).
     *
     * @phpstan-impure
     */
    public static function read(string $path): ?DateTimeImmutable
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
            $at   = is_array($data) && is_string($data['restored_at'] ?? null) ? new DateTimeImmutable($data['restored_at']) : null;
        } catch (Throwable) {
            $at = null;
        }

        // A damaged marker still means "a restore ran": its modification time stands in for the instant.
        return $at ?? new DateTimeImmutable('@' . (int) @filemtime($path));
    }

    /**
     * Refuses an incremental after $parentId when a restore happened at or after the end of the parent's capture
     * (`binlog_captured_at`, else `snapshot_at`, else `created_at`).
     *
     * @param array<string, mixed> $parentMeta
     *
     * @throws BinlogGapException
     */
    public static function assertOpen(string $path, string $parentId, array $parentMeta): void
    {
        $restoredAt = self::read($path);
        if (!$restoredAt instanceof DateTimeImmutable) {
            return;
        }
        $end = null;
        foreach (['binlog_captured_at', 'snapshot_at', 'created_at'] as $key) {
            if (is_string($parentMeta[$key] ?? null)) {
                try {
                    $end = new DateTimeImmutable($parentMeta[$key]);
                    break;
                } catch (Throwable) {
                }
            }
        }
        if (!$end instanceof DateTimeImmutable || $end->getTimestamp() <= $restoredAt->getTimestamp()) {
            throw new BinlogGapException(BinlogGapException::CLOSED_BY_RESTORE, sprintf('A restore ran on %s, after backup "%s" was taken: its chain is closed (the restore is in the binary log). Take a new full backup.', $restoredAt->format(DATE_ATOM), $parentId));
        }
    }
}
