<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use Nowo\SiteBackupBundle\Exception\ChainBrokenException;

use function count;
use function sprintf;

/**
 * Metadata checks of a backup chain (1.36+), pure: run before anything is extracted or applied. The archive
 * checksums are checked separately (the chain restore verifies every archive too).
 *
 * A chain is valid when:
 * - the first link is a full backup with chain metadata and every next link is an incremental of the same chain whose
 *   parent is the link before it (no missing parent, no reordering, creation times never go back);
 * - database: either the full has a dump with binlog coordinates and every incremental carries the binary logs from
 *   exactly where the previous link ends (same server UUID, same database, contiguous files), or no link has any
 *   database part;
 * - files: either the full has a file manifest and every incremental is a delta against the manifest of the link before
 *   it (`base_manifest_sha256` = parent's `file_manifest_sha256`), or no link has any file part;
 * - every incremental carries a database or a file part.
 */
final class ChainVerifier
{
    /**
     * @param list<ChainLink> $links root first
     *
     * @return list<string> problems; empty = valid
     */
    public static function verify(array $links): array
    {
        if ($links === []) {
            return ['The chain is empty.'];
        }

        $root     = $links[0];
        $problems = [];
        if ($root->type !== ChainLink::TYPE_FULL || $root->chainId === null || $root->parentId !== null) {
            $problems[] = sprintf('Backup %s is not the full backup of a chain.', $root->id);
        }

        $withDatabase = $root->hasDump;
        $withFiles    = $root->hasFileManifest;
        if ($withDatabase && count($links) > 1 && $root->end === null) {
            $problems[] = sprintf('Full backup %s has a database dump without binlog coordinates: its incrementals cannot be replayed.', $root->id);
        }
        if ($withFiles && $root->fileManifestSha256 === null) {
            $problems[] = sprintf('Full backup %s has no file_manifest_sha256.', $root->id);
        }

        for ($i = 1, $n = count($links); $i < $n; ++$i) {
            $link   = $links[$i];
            $parent = $links[$i - 1];
            foreach (self::link($link, $parent, $root, $withDatabase, $withFiles) as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param list<ChainLink> $links
     *
     * @throws ChainBrokenException
     */
    public static function assertValid(array $links): void
    {
        $problems = self::verify($links);
        if ($problems !== []) {
            throw new ChainBrokenException($problems);
        }
    }

    /**
     * @return list<string>
     */
    private static function link(ChainLink $link, ChainLink $parent, ChainLink $root, bool $withDatabase, bool $withFiles): array
    {
        $problems = [];
        if (!$link->isIncremental()) {
            $problems[] = sprintf('Backup %s is not an incremental backup.', $link->id);
        }
        if ($link->chainId === null || $link->chainId !== $root->chainId) {
            $problems[] = sprintf('Backup %s belongs to another chain.', $link->id);
        }
        if ($link->storage !== $root->storage) {
            $problems[] = sprintf('Backup %s is stored with another format, encryption or key than the full backup (%s vs %s): one chain, one format and key.', $link->id, $link->storage, $root->storage);
        }
        if ($link->parentId !== $parent->id) {
            $problems[] = sprintf('The parent of backup %s is missing (expected %s).', $link->id, $parent->id);
        }
        if ($link->createdAt < $parent->createdAt) {
            $problems[] = sprintf('Backup %s is older than its parent %s.', $link->id, $parent->id);
        }
        if (!$link->hasBinlog() && $link->baseManifestSha256 === null) {
            $problems[] = sprintf('Incremental backup %s holds neither binary logs nor a file delta.', $link->id);
        }

        foreach ($withDatabase ? self::database($link, $parent, $root) : self::noDatabase($link) as $problem) {
            $problems[] = $problem;
        }

        if ($withFiles) {
            if ($link->baseManifestSha256 === null || $link->baseManifestSha256 !== $parent->fileManifestSha256) {
                $problems[] = sprintf('The file delta of backup %s is not based on the files of %s.', $link->id, $parent->id);
            }
            if (!$link->hasFileManifest || $link->fileManifestSha256 === null) {
                $problems[] = sprintf('Backup %s has no file manifest.', $link->id);
            }
        } elseif ($link->baseManifestSha256 !== null || $link->hasFileManifest) {
            $problems[] = sprintf('Backup %s has a file delta but the full backup has no file manifest.', $link->id);
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function database(ChainLink $link, ChainLink $parent, ChainLink $root): array
    {
        if (!$link->from instanceof BinlogCoordinates || !$link->end instanceof BinlogCoordinates) {
            return [sprintf('Backup %s has no binary logs although the chain has a database.', $link->id)];
        }

        $problems = [];
        if ($link->binlogCapture !== null && $link->binlogCapture !== IncrementalArtifact::RAW && $link->binlogCapture !== IncrementalArtifact::FILTERED) {
            $problems[] = sprintf('Backup %s has an unknown binlog capture mode.', $link->id);
        }
        if ($parent->isIncremental() && $link->binlogCapture !== $parent->binlogCapture) {
            $problems[] = sprintf('Backup %s mixes raw and filtered binlog captures in one chain.', $link->id);
        }
        if ($link->binlogCapture === IncrementalArtifact::FILTERED && $link->binlogFiles !== [] && $link->binlogEvents === null) {
            $problems[] = sprintf('Backup %s is a filtered capture without its events file.', $link->id);
        }
        if ($root->end instanceof BinlogCoordinates && $link->end->serverUuid !== $root->end->serverUuid) {
            $problems[] = sprintf('Backup %s comes from another MySQL server (server_uuid differs).', $link->id);
        }
        if (!$parent->end instanceof BinlogCoordinates || !$link->from->equals($parent->end)) {
            $problems[] = sprintf('The binary logs of backup %s do not start where %s ends (gap or overlap).', $link->id, $parent->id);
        }
        if ($link->database === null || $link->database !== $root->database) {
            $problems[] = sprintf('Backup %s captured another database.', $link->id);
        }

        $names = $link->binlogFileNames();
        if ($names === []) {
            if (!$link->from->equals($link->end)) {
                $problems[] = sprintf('Backup %s lists no binary log although its coordinates moved.', $link->id);
            }

            return $problems;
        }

        $basename = $link->from->basename();
        $expected = $link->from->sequence();
        $first    = BinlogCoordinates::sequenceOf($names[0]);
        if ($first !== $expected && $first !== $expected + 1) {
            $problems[] = sprintf('The binary logs of backup %s do not start at %s.', $link->id, $link->from->file);
        }
        // A log read to its end by the parent is never captured again (its events would be replayed twice).
        if ($names[0] === $link->from->file && $link->from->position >= $link->binlogFiles[0]['size']) {
            $problems[] = sprintf('The binary logs of backup %s overlap those of %s.', $link->id, $parent->id);
        }
        $previous = null;
        foreach ($names as $name) {
            $sequence = BinlogCoordinates::sequenceOf($name);
            $sameBase = BinlogCoordinates::basenameOf($name) === $basename;
            if (!$sameBase || ($previous !== null && $sequence !== $previous + 1)) {
                $problems[] = sprintf('The binary logs of backup %s are not contiguous.', $link->id);
                break;
            }
            $previous = $sequence;
        }
        if ($names[count($names) - 1] !== $link->end->file) {
            $problems[] = sprintf('The binary logs of backup %s do not end at %s.', $link->id, $link->end->file);
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function noDatabase(ChainLink $link): array
    {
        return $link->hasBinlog() || $link->hasDump
            ? [sprintf('Backup %s has a database part but the full backup has none.', $link->id)]
            : [];
    }
}
