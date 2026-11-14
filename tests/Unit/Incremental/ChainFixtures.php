<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Model\BackupArtifact;

use function hash;
use function sprintf;

/**
 * Chain metadata builders for unit tests: a full backup (dump + coordinates + manifest) and incrementals that continue
 * it exactly like the archiver writes them.
 */
final class ChainFixtures
{
    public const UUID  = '3e11fa47-71ca-11e1-9e33-c80aa9429562';
    public const OTHER = 'aaaaaaaa-71ca-11e1-9e33-c80aa9429562';

    public static function manifest(int $version): FileManifest
    {
        return new FileManifest(['a.txt' => [1, 1, hash('sha256', 'a' . $version)]], hash('sha256', 'scope'));
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, string> $checksums
     */
    public static function artifact(string $id, string $at, array $meta, array $checksums = []): BackupArtifact
    {
        return new BackupArtifact($id, $id . '.tar.gz', '/nowhere/' . $id . '.tar.gz', new DateTimeImmutable($at), 1, hash('sha256', $id), $checksums, $meta);
    }

    /**
     * @param array<string, mixed> $override
     */
    public static function full(string $id = 'full', array $override = []): BackupArtifact
    {
        $manifest = self::manifest(0);

        return self::artifact($id, '2026-11-01 00:00:00 UTC', $override + [
            'type'                 => 'full',
            'chain_id'             => $id,
            'parent_id'            => null,
            'database_dump'        => 'database/dump.sql',
            'binlog_database'      => 'app',
            'includes'             => ['config'],
            'file_manifest'        => $manifest->toArray(),
            'file_manifest_sha256' => $manifest->sha256(),
        ] + (new BinlogCoordinates('binlog.000002', 1480, self::UUID, 'x:1-3'))->toArray());
    }

    /**
     * @param array<string, mixed> $override
     */
    public static function incremental(string $id, BackupArtifact $parent, int $n, array $override = [], string $chainId = 'full'): BackupArtifact
    {
        $from     = BinlogCoordinates::fromArray($parent->getMeta()) ?? new BinlogCoordinates('binlog.000002', 1480, self::UUID);
        $toFile   = 'binlog.' . sprintf('%06d', $from->sequence() + 1);
        $to       = new BinlogCoordinates($toFile, 900 + $n, self::UUID);
        $manifest = self::manifest($n);
        $base     = $parent->getMeta()['file_manifest_sha256'] ?? null;

        return self::artifact($id, sprintf('2026-11-01 %02d:00:00 UTC', $n), $override + [
            'type'            => 'incremental',
            'chain_id'        => $chainId,
            'parent_id'       => $parent->getId(),
            'binlog_database' => 'app',
            'binlog_from'     => $from->toArray(),
            'binlog_files'    => [
                ['name' => $from->file, 'sha256' => hash('sha256', $id . $from->file), 'size' => 2000],
                ['name' => $toFile, 'sha256' => hash('sha256', $id . $toFile), 'size' => 900 + $n],
            ],
            'first_event_at'       => sprintf('2026-11-01T%02d:00:00+00:00', $n - 1),
            'last_event_at'        => sprintf('2026-11-01T%02d:59:00+00:00', $n - 1),
            'file_manifest'        => $manifest->toArray(),
            'file_manifest_sha256' => $manifest->sha256(),
            'base_manifest_sha256' => $base,
            'includes'             => ['config'],
        ] + $to->toArray());
    }
}
