<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Restore;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Incremental\BackupChain;
use Nowo\SiteBackupBundle\Incremental\BinlogBoundaries;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogReplayer;
use Nowo\SiteBackupBundle\Incremental\BinlogSchemaFilter;
use Nowo\SiteBackupBundle\Incremental\ChainPaths;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreReport;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreStep;
use Nowo\SiteBackupBundle\Incremental\ChainVerifier;
use Nowo\SiteBackupBundle\Incremental\DumpObjects;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Worker\WorkerRestartSignal;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function array_key_first;
use function array_key_last;
use function array_slice;
use function array_splice;
use function array_values;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function filesize;
use function hash_file;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function json_decode;
use function rtrim;
use function sprintf;
use function unlink;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * Restores a backup chain (1.36+, CLI first): the full backup, then its incrementals, up to an optional point in time.
 *
 * Fail closed, in this order:
 * 1. **verify, touching nothing**: chain metadata ({@see ChainVerifier}: parents present, contiguous binlogs, one
 *    server, manifest links), `$until` not before the full backup, every archive's SHA-256 / signature / MANIFEST, the
 *    binlog hashes of the meta against the MANIFEST, the database name;
 * 2. **prepare, touching nothing**: extract each archive into a private staging directory and re-hash what will be
 *    used; fold the file deltas (changed files, `DELETED.json`) into the final file set and check it file by file
 *    against the `file_manifest` of the last link; decode the binary logs (`--stop-datetime`);
 * 3. **apply**: import the full backup's dump, replay the binary logs, write the files, delete the deleted files.
 *
 * Any failure in 1–2 throws {@see ChainBrokenException} (nothing was changed). A failure in 3 throws
 * {@see ChainRestoreException} whose report lists every step as applied / failed / not applied. File deltas are
 * applied up to the last incremental created at or before `$until`; binary logs up to `$until` itself.
 *
 * Holds no state between calls; every staging directory is removed in a `finally`.
 */
final class ChainRestorer
{
    public function __construct(
        private readonly BackupArchiver $archiver,
        private readonly RestorePathGuard $guard,
        private readonly string $projectDir,
        private readonly ?DumpImporter $importer = null,
        private readonly ?BinlogReplayer $replayer = null,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly ?WorkerRestartSignal $workerRestartSignal = null,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $until last second to restore (inclusive); null = the head of the chain
     * @param (Closure(): void)|null $heartbeat called regularly (the manager refreshes the operation lock)
     * @param bool $recreateDatabase drop and re-create the target schema before the dump import (PP-H1 opt-in);
     *                               by default a target holding objects the dump does not re-create is refused
     *
     * @throws ChainBrokenException when the chain cannot be restored (nothing changed)
     * @throws ChainRestoreException when applying failed (the report says what was applied)
     */
    public function restore(BackupChain $chain, ?DateTimeImmutable $until = null, #[SensitiveParameter] ?ArchivePassword $password = null, ?Closure $heartbeat = null, bool $recreateDatabase = false): ChainRestoreReport
    {
        $links = $chain->links;
        ChainVerifier::assertValid($links);
        $root = $links[0];
        // PP-L8: the dump's snapshot is at most as recent as `snapshot_at` (recorded right after the dump).
        $floor = $root->snapshotAt ?? $root->createdAt;
        if ($until instanceof DateTimeImmutable && $until < $floor) {
            throw new ChainBrokenException([sprintf('--until (%s) is before the full backup %s was taken (%s): nothing to restore to.', $until->format(DATE_ATOM), $root->id, $floor->format(DATE_ATOM))]);
        }

        [$steps, $notes, $binlogLinks, $fileLinks] = $this->plan($chain, $until);
        $tick                                      = $heartbeat ?? static function (): void {};

        $this->verifyArchives($chain, $password, $tick);
        if ($binlogLinks !== [] && ($this->replayer?->databaseName() !== $root->database)) {
            throw new ChainBrokenException([sprintf('The chain captured database "%s"; the configured database differs (or no binlog replayer is configured): replays would write elsewhere.', (string) $root->database)]);
        }
        if ($root->hasDump && !$this->importer instanceof DumpImporter) {
            throw new ChainBrokenException(['The chain has a database dump but no SQL importer is configured (backup.database.url).']);
        }
        if ($root->hasDump && $root->database !== null && $this->importer?->databaseName() !== $root->database) {
            throw new ChainBrokenException([sprintf('The chain backed up database "%s"; the restore connection names another one.', $root->database)]);
        }
        if ($recreateDatabase && (!$root->hasDump || !$this->replayer instanceof BinlogReplayer)) {
            throw new ChainBrokenException(['--recreate-database needs a chain with a database dump and the restore connection.']);
        }

        $staging  = $this->archiver->createPrivateWorkDirectory('chain-' . $root->id . '-');
        $touched  = false;
        $position = 0;

        try {
            // 2. Prepare: extract, re-hash, fold, decode. Still nothing applied.
            $dirs = [];
            foreach ($chain->artifacts as $i => $artifact) {
                if ($i > 0 && !isset($binlogLinks[$i]) && !isset($fileLinks[$i])) {
                    continue;
                }
                $dirs[$i] = $staging . '/' . $i;
                $this->archiver->extractTo($artifact, $dirs[$i], $tick, $password);
            }
            $dump                = $root->hasDump ? $this->stagedFile($chain->root(), $dirs[0], ChainPaths::DUMP) : null;
            [$files, $deletions] = $this->foldFiles($chain, $dirs, $fileLinks);
            $schemaMissing       = $dump !== null && !$recreateDatabase && $this->preflightSchema($dump);
            $decoded             = $binlogLinks !== [] ? $this->replayFile($chain, $dirs, $binlogLinks, $staging, $until, $tick) : null;

            // 3. Apply, step by step.
            foreach ($steps as $index => $step) {
                if ($step->status === ChainRestoreStep::SKIPPED) {
                    continue;
                }
                $position          = $index;
                $touched           = true;
                [$status, $detail] = $this->apply($step, $dump, $decoded, $files, $deletions, $tick, $recreateDatabase, $schemaMissing);
                $steps[$index]     = $step->with($status, $detail);
            }

            // PP-L7: files that could not be written make the restore incomplete (not failed: nothing to roll back).
            $complete = true;
            foreach ($steps as $step) {
                $complete = $complete && $step->status !== ChainRestoreStep::INCOMPLETE;
            }

            return new ChainRestoreReport($chain->ids(), $steps, $complete, $until, $complete ? null : 'Some files could not be written (see the files step).', $notes, $this->databaseAt($chain, $binlogLinks, $until), $this->filesAsOf($chain, $fileLinks));
        } catch (ChainBrokenException $e) {
            if (!$touched) {
                throw $e;
            }

            throw $this->failure($chain, $steps, $position, $until, $notes, $e);
        } catch (Throwable $e) {
            if (!$touched) {
                throw new ChainBrokenException([SecretRedactor::fromThrowable($e)]);
            }

            throw $this->failure($chain, $steps, $position, $until, $notes, $e);
        } finally {
            $this->filesystem->remove($staging);
            if ($touched && $this->hasFileSteps($steps)) {
                $this->workerRestartSignal?->request('restore-chain:' . $chain->head()->getId());
            }
        }
    }

    /**
     * Planned steps and which incrementals contribute binlogs / file deltas.
     *
     * @return array{0: list<ChainRestoreStep>, 1: list<string>, 2: array<int, true>, 3: array<int, true>}
     */
    private function plan(BackupChain $chain, ?DateTimeImmutable $until): array
    {
        $links = $chain->links;
        $root  = $links[0];
        $steps = [];
        $notes = [];
        if ($root->hasDump) {
            $steps[] = new ChainRestoreStep($root->id, ChainRestoreStep::PART_DATABASE_DUMP, ChainRestoreStep::NOT_APPLIED);
        }

        $binlogLinks = [];
        $fileLinks   = [];
        for ($i = 1, $n = count($links); $i < $n; ++$i) {
            $link = $links[$i];
            if ($link->hasBinlog()) {
                // Events after $until are cut by --stop-datetime; a capture that starts after it adds nothing.
                $after = $until instanceof DateTimeImmutable && $link->firstEventAt !== null && $link->firstEventAt > $until;
                if ($link->binlogFiles === [] || $after) {
                    $steps[] = new ChainRestoreStep($link->id, ChainRestoreStep::PART_BINLOG, ChainRestoreStep::SKIPPED, $after ? 'after --until' : 'no events');
                } else {
                    $binlogLinks[$i] = true;
                }
            }
        }
        if ($binlogLinks !== []) {
            // One mysqlbinlog stream for all captures: they are applied together.
            $first   = $links[array_key_first($binlogLinks)]->id;
            $last    = $links[array_key_last($binlogLinks)]->id;
            $steps[] = new ChainRestoreStep($first === $last ? $first : $first . '..' . $last, ChainRestoreStep::PART_BINLOG, ChainRestoreStep::NOT_APPLIED, $until instanceof DateTimeImmutable ? 'up to ' . $until->format(DATE_ATOM) : 'every event');
        }

        $filesAt = count($steps);
        for ($i = 1, $n = count($links); $i < $n; ++$i) {
            $link = $links[$i];
            if ($link->baseManifestSha256 === null) {
                continue;
            }
            if ($until instanceof DateTimeImmutable && $link->createdAt > $until) {
                $steps[] = new ChainRestoreStep($link->id, ChainRestoreStep::PART_FILES, ChainRestoreStep::SKIPPED, 'created after --until');
                continue;
            }
            $fileLinks[$i] = true;
        }
        if ($this->hasFiles($chain->root()) || $fileLinks !== []) {
            // The full backup's files with every applicable delta folded in: written in one pass.
            $lastFiles = $fileLinks === [] ? $root->id : $links[array_key_last($fileLinks)]->id;
            $files     = new ChainRestoreStep($lastFiles === $root->id ? $root->id : $root->id . '..' . $lastFiles, ChainRestoreStep::PART_FILES, ChainRestoreStep::NOT_APPLIED, 'the final file set');
            array_splice($steps, $filesAt, 0, [$files]);
        }
        if ($fileLinks !== []) {
            $steps[] = new ChainRestoreStep($links[array_key_last($fileLinks)]->id, ChainRestoreStep::PART_DELETIONS, ChainRestoreStep::NOT_APPLIED, 'files deleted by the incrementals');
        }

        $head = $links[count($links) - 1];
        if ($until instanceof DateTimeImmutable && $until > $head->createdAt) {
            $notes[] = sprintf('Nothing was captured after %s: the database is restored to the head of the chain.', $head->createdAt->format(DATE_ATOM));
        }
        if ($root->hasFileManifest === false && $this->hasFiles($chain->root()) && count($links) > 1) {
            $notes[] = 'The chain records no file deltas: files are restored as they were in the full backup.';
        }
        if ($fileLinks !== [] && $until instanceof DateTimeImmutable) {
            $lastFiles = $links[array_key_last($fileLinks)];
            $notes[]   = sprintf('Files are restored as of %s (incremental %s).', $lastFiles->createdAt->format(DATE_ATOM), $lastFiles->id);
        }

        return [$steps, $notes, $binlogLinks, $fileLinks];
    }

    /**
     * PP-H1: the target schema must hold nothing the dump does not re-create (a table, view or trigger created after
     * the full backup would make the replay fail half-way, after the dump import). Nothing is changed here.
     *
     * @return bool true when the schema does not exist yet (the dump step creates it)
     */
    private function preflightSchema(string $dump): bool
    {
        if (!$this->replayer instanceof BinlogReplayer) {
            return false;
        }
        $objects = $this->replayer->schemaObjects();
        if ($objects === null) {
            return true;
        }
        $known = DumpObjects::parse($dump);
        $extra = [];
        foreach ($objects as $object) {
            if (!isset($known[$object])) {
                $extra[] = $object;
            }
        }
        if ($extra !== []) {
            throw new ChainBrokenException([sprintf('The target schema holds %d object(s) the full backup does not re-create (%s%s): restore into an empty schema or server, or pass --recreate-database to drop and re-create the schema first (stored routines and events are in the dump only when the full backup was taken with dumpRoutines / --routines; otherwise --recreate-database drops them).', count($extra), implode(', ', array_slice($extra, 0, 20)), count($extra) > 20 ? ', …' : '')]);
        }

        return false;
    }

    /**
     * The replay file, checked event by event (PP-M1 / PP-M2): filtered captures are concatenated (and cut at
     * `--until`), raw captures are decoded without `--database`; any event outside the schema refuses the restore.
     *
     * @param array<int, string> $dirs
     * @param array<int, true> $binlogLinks
     */
    private function replayFile(BackupChain $chain, array $dirs, array $binlogLinks, string $staging, ?DateTimeImmutable $until, Closure $tick): ?string
    {
        $root      = $chain->links[0];
        $mode      = $chain->links[(int) array_key_first($binlogLinks)]->binlogCapture ?? IncrementalArtifact::RAW;
        $paths     = [];
        $sequences = [];
        foreach ($binlogLinks as $i => $_) {
            $link = $chain->links[$i];
            if ($mode === IncrementalArtifact::FILTERED) {
                $paths[]     = $this->stagedFile($chain->artifacts[$i], $dirs[$i], ChainPaths::EVENTS);
                $sequences[] = $this->recordedSequence($chain->artifacts[$i], $dirs[$i]);
                continue;
            }
            foreach ($link->binlogFiles as $file) {
                $paths[] = $this->stagedFile($chain->artifacts[$i], $dirs[$i], ChainPaths::BINLOG_DIRECTORY . $file['name']);
            }
        }
        if (!$this->replayer instanceof BinlogReplayer || !$root->end instanceof BinlogCoordinates) {
            return null;
        }

        // PP-D1-H1: the boundaries of every event are checked against the structure (recorded index of filtered
        // captures, binary headers of raw ones); the cut at --until is made here for both.
        if ($mode === IncrementalArtifact::RAW) {
            $start      = $paths !== [] && basename($paths[0]) === $root->end->file ? $root->end->position : null;
            $boundaries = BinlogBoundaries::fromRawFiles($paths, $start);
            $decoded    = $staging . '/decoded.sql';
            $this->replayer->decode($paths, $root->end, null, $decoded, $tick);
            $paths = [$decoded];
        } else {
            $boundaries = BinlogBoundaries::recorded($sequences);
        }
        $replay = $staging . '/replay.sql';
        $result = BinlogSchemaFilter::run($paths, $replay, (string) $root->database, BinlogSchemaFilter::CHECK, $until?->modify('+1 second'), $boundaries);
        if (!$result->isClean()) {
            throw new ChainBrokenException([sprintf('The binary logs hold %d event(s) outside schema %s, that cannot be attributed to it or whose boundaries do not match; nothing was applied: %s', $result->problemCount, (string) $root->database, implode('; ', $result->problems))]);
        }

        return $replay;
    }

    /**
     * The boundary sequence recorded at capture time (`events.idx`, covered by the archive checksums).
     *
     * @return list<array{0: int, 1: ?int}>
     */
    private function recordedSequence(BackupArtifact $artifact, string $dir): array
    {
        try {
            $raw = json_decode((string) file_get_contents($this->stagedFile($artifact, $dir, ChainPaths::EVENTS_INDEX)), true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $raw = null;
        }
        $sequence = [];
        foreach (is_array($raw) ? $raw : [null] as $entry) {
            if (!is_array($entry) || !is_int($entry[0] ?? null) || (($entry[1] ?? null) !== null && !is_int($entry[1]))) {
                throw new ChainBrokenException([sprintf('Archive %s: the event index is not a list of positions.', $artifact->getId())]);
            }
            $sequence[] = [$entry[0], $entry[1] ?? null];
        }

        return $sequence;
    }

    /**
     * @param array<int, true> $binlogLinks
     */
    private function databaseAt(BackupChain $chain, array $binlogLinks, ?DateTimeImmutable $until): ?DateTimeImmutable
    {
        $root = $chain->links[0];
        if (!$root->hasDump) {
            return null;
        }
        if ($binlogLinks === []) {
            return $root->snapshotAt ?? $root->createdAt;
        }
        $last = $chain->links[(int) array_key_last($binlogLinks)];
        $head = $last->lastEventAt ?? $last->createdAt;

        return $until instanceof DateTimeImmutable && $until < $head ? $until : $head;
    }

    /**
     * @param array<int, true> $fileLinks
     */
    private function filesAsOf(BackupChain $chain, array $fileLinks): ?DateTimeImmutable
    {
        if (!$this->hasFiles($chain->root())) {
            return null;
        }

        return $chain->links[$fileLinks === [] ? 0 : (int) array_key_last($fileLinks)]->createdAt;
    }

    private function hasFiles(BackupArtifact $artifact): bool
    {
        foreach ($artifact->getChecksums() as $path => $_) {
            if (!$this->isSpecial((string) $path)) {
                return true;
            }
        }

        return false;
    }

    private function isSpecial(string $path): bool
    {
        return ChainPaths::isReserved($path);
    }

    /**
     * Every archive of the chain: SHA-256, signature and MANIFEST checksums; the binlog hashes in the meta must be the
     * MANIFEST's, every file delta must carry DELETED.json.
     */
    private function verifyArchives(BackupChain $chain, ?ArchivePassword $password, Closure $tick): void
    {
        $problems = [];
        foreach ($chain->artifacts as $i => $artifact) {
            $result = $this->archiver->verifyIntegrity($artifact, $tick, $password);
            if (!$result['ok']) {
                $problems[] = sprintf('Archive %s: %s', $artifact->getId(), implode('; ', $result['errors']));
                continue;
            }
            $link = $chain->links[$i];
            if ($link->binlogCapture === IncrementalArtifact::FILTERED) {
                $events = $link->binlogEvents;
                if ($link->binlogFiles !== [] && ($events === null || $events['path'] !== ChainPaths::EVENTS || ($result['checksums'][ChainPaths::EVENTS] ?? null) !== $events['sha256'] || !isset($result['checksums'][ChainPaths::EVENTS_INDEX]))) {
                    $problems[] = sprintf('Archive %s: the binary log events do not match their meta.', $artifact->getId());
                }
            } else {
                foreach ($link->binlogFiles as $file) {
                    if (($result['checksums'][ChainPaths::BINLOG_DIRECTORY . $file['name']] ?? null) !== $file['sha256']) {
                        $problems[] = sprintf('Archive %s: binary log %s does not match its meta.', $artifact->getId(), $file['name']);
                    }
                }
            }
            if ($link->baseManifestSha256 !== null && !isset($result['checksums'][ChainPaths::DELETED])) {
                $problems[] = sprintf('Archive %s: the file delta has no DELETED.json.', $artifact->getId());
            }
            if ($link->hasDump && !isset($result['checksums'][ChainPaths::DUMP])) {
                $problems[] = sprintf('Archive %s: the database dump is missing.', $artifact->getId());
            }
        }
        if ($problems !== []) {
            throw new ChainBrokenException($problems);
        }
    }

    /**
     * A staged file, re-hashed against the archive's checksums (the archive was verified before it was extracted).
     */
    private function stagedFile(BackupArtifact $artifact, string $dir, string $relative): string
    {
        $path     = $dir . '/' . $relative;
        $expected = $artifact->getChecksums()[$relative] ?? null;
        if (!is_file($path) || is_link($path) || $expected === null || hash_file('sha256', $path) !== $expected) {
            throw new ChainBrokenException([sprintf('Archive %s: %s changed or vanished after verification.', $artifact->getId(), $relative)]);
        }

        return $path;
    }

    /**
     * Folds the file deltas into the final file set and checks it against the last link's file manifest.
     *
     * @param array<int, string> $dirs
     * @param array<int, true> $fileLinks
     *
     * @return array{0: array<string, string>, 1: array<string, true>} relative => staged path, relative deletions
     */
    private function foldFiles(BackupChain $chain, array $dirs, array $fileLinks): array
    {
        $files     = [];
        $deletions = [];
        $sums      = [];
        $last      = 0;
        foreach ($chain->artifacts as $i => $artifact) {
            if ($i > 0 && !isset($fileLinks[$i])) {
                continue;
            }
            if ($i > 0) {
                foreach ($this->deletedList($artifact, $dirs[$i]) as $deleted) {
                    unset($files[$deleted], $sums[$deleted]);
                    $deletions[$deleted] = true;
                }
                $last = $i;
            }
            foreach ($artifact->getChecksums() as $relative => $sum) {
                $relative = (string) $relative;
                if ($this->isSpecial($relative)) {
                    continue;
                }
                $files[$relative] = $this->stagedFile($artifact, $dirs[$i], $relative);
                $sums[$relative]  = $sum;
                unset($deletions[$relative]);
            }
        }

        $meta = $chain->artifacts[$last]->getMeta();
        if (is_array($meta['file_manifest'] ?? null)) {
            $this->assertMatchesManifest($chain->artifacts[$last], FileManifest::fromArray($meta['file_manifest']), $sums);
        }

        return [$files, $deletions];
    }

    /**
     * @return list<string>
     */
    private function deletedList(BackupArtifact $artifact, string $dir): array
    {
        $path = $this->stagedFile($artifact, $dir, ChainPaths::DELETED);
        try {
            $list = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $list = null;
        }
        if (!is_array($list)) {
            throw new ChainBrokenException([sprintf('Archive %s: DELETED.json is not a list of paths.', $artifact->getId())]);
        }
        $paths = [];
        foreach ($list as $entry) {
            try {
                if (!is_string($entry)) {
                    throw new InvalidArgumentException('not a string');
                }
                FileManifest::assertPath($entry);
            } catch (InvalidArgumentException) {
                throw new ChainBrokenException([sprintf('Archive %s: DELETED.json holds an unsafe path.', $artifact->getId())]);
            }
            $paths[] = $entry;
        }

        return $paths;
    }

    /**
     * The folded set must be exactly the files of the last link's manifest, with the same SHA-256 each.
     *
     * @param array<string, string> $sums relative path => SHA-256 of the newest archive that holds it
     */
    private function assertMatchesManifest(BackupArtifact $artifact, FileManifest $manifest, array $sums): void
    {
        $problems = [];
        foreach ($manifest->entries as $path => $entry) {
            $path = (string) $path;
            if (($sums[$path] ?? null) !== $entry[2]) {
                $problems[] = sprintf('%s is missing from the chain or does not match the manifest of %s.', $path, $artifact->getId());
            }
        }
        foreach ($sums as $path => $_) {
            if ($manifest->get((string) $path) === null) {
                $problems[] = sprintf('%s is in the chain but not in the manifest of %s.', $path, $artifact->getId());
            }
        }
        if ($problems !== []) {
            throw new ChainBrokenException($problems);
        }
    }

    /**
     * @param array<string, string> $files
     * @param array<string, true> $deletions
     *
     * @return array{0: string, 1: string} status, detail
     */
    private function apply(ChainRestoreStep $step, ?string $dump, ?string $decoded, array $files, array $deletions, Closure $tick, bool $recreate, bool $schemaMissing): array
    {
        return match ($step->part) {
            ChainRestoreStep::PART_DATABASE_DUMP => [ChainRestoreStep::APPLIED, $this->importDump((string) $dump, $tick, $recreate, $schemaMissing)],
            ChainRestoreStep::PART_BINLOG        => [ChainRestoreStep::APPLIED, $this->replay($decoded, $tick)],
            ChainRestoreStep::PART_FILES         => $this->writeFiles($files, $tick),
            default                              => [ChainRestoreStep::APPLIED, $this->deleteFiles($deletions)],
        };
    }

    private function importDump(string $dump, Closure $tick, bool $recreate, bool $schemaMissing): string
    {
        $prefix = '';
        if ($recreate || $schemaMissing) {
            $this->replayer?->createSchema($recreate);
            $prefix = $recreate ? 'schema dropped and re-created, ' : 'schema created, ';
        }
        $this->importer?->import($dump, $tick);

        return sprintf('%sdump imported (%d bytes)', $prefix, (int) filesize($dump));
    }

    private function replay(?string $decoded, Closure $tick): string
    {
        // The plan has a binlog step only when there are logs to decode (and a replayer, checked up front).
        $this->replayer?->apply((string) $decoded, $tick);

        return 'binary logs replayed';
    }

    /**
     * Protected, secret and runtime paths are skipped by design; a link / directory in the way or a parent leaving the
     * project leaves the step INCOMPLETE (PP-L7).
     *
     * @param array<string, string> $files
     *
     * @return array{0: string, 1: string}
     */
    private function writeFiles(array $files, Closure $tick): array
    {
        $project    = rtrim($this->projectDir, '/\\');
        $written    = 0;
        $skipped    = [];
        $notWritten = [];
        foreach ($files as $relative => $staged) {
            $relative = (string) $relative;
            $refusal  = $this->guard->refusal($relative);
            if ($refusal !== null) {
                $skipped[] = $relative . ' (' . $refusal . ')';
                continue;
            }
            $dest = $project . '/' . $relative;
            if (!$this->guard->parentStaysInside($relative) || is_link($dest) || is_dir($dest)) {
                $notWritten[] = $relative . (is_link($dest) || is_dir($dest) ? ' (a link or directory is in the way)' : ' (parent outside the project)');
                continue;
            }
            $this->filesystem->mkdir(dirname($dest));
            $this->writeFile($staged, $dest);
            ++$written;
            $tick();
        }

        $detail = sprintf('%d file(s) written', $written)
            . ($skipped === [] ? '' : '; skipped: ' . implode(', ', $skipped))
            . ($notWritten === [] ? '' : '; NOT written: ' . implode(', ', $notWritten));

        return [$notWritten === [] ? ChainRestoreStep::APPLIED : ChainRestoreStep::INCOMPLETE, $detail];
    }

    /**
     * PP-L3: the copy goes to a fresh, unpredictable file created with `x` (never through a planted link) next to the
     * destination, then replaces it with rename() (which replaces a link, never follows it).
     */
    private function writeFile(string $staged, string $dest): void
    {
        $tmp = dirname($dest) . '/.nowo-restore-' . bin2hex(random_bytes(12));
        $out = @fopen($tmp, 'x');
        if ($out === false) {
            throw new ChainBrokenException([sprintf('Unable to write %s.', $dest)]);
        }
        try {
            // $staged was re-hashed by stagedFile(): a readable regular file.
            $in = @fopen($staged, 'r') ?: throw new ChainBrokenException([sprintf('Unable to read the staged copy of %s.', $dest)]);
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        @chmod($tmp, (fileperms($staged) & 0o777) | 0o600);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);

            throw new ChainBrokenException([sprintf('Unable to write %s.', $dest)]);
        }
    }

    /**
     * @param array<string, true> $deletions
     */
    private function deleteFiles(array $deletions): string
    {
        $project = rtrim($this->projectDir, '/\\');
        $deleted = 0;
        $absent  = 0;
        $skipped = [];
        foreach ($deletions as $relative => $_) {
            // Never a path of $files: a re-added path leaves the deletions when the deltas are folded.
            $relative = (string) $relative;
            $refusal  = $this->guard->refusal($relative) ?? ($this->guard->parentStaysInside($relative) ? null : 'parent outside the project');
            if ($refusal !== null) {
                $skipped[] = $relative . ' (' . $refusal . ')';
                continue;
            }
            $path = $project . '/' . $relative;
            if (is_link($path) || is_file($path)) {
                if (!@unlink($path)) {
                    throw new ChainBrokenException([sprintf('Unable to delete %s.', $relative)]);
                }
                ++$deleted;
            } elseif (is_dir($path)) {
                $skipped[] = $relative . ' (a directory)';
            } else {
                ++$absent;
            }
        }

        return sprintf('%d file(s) deleted, %d already absent%s', $deleted, $absent, $skipped === [] ? '' : '; skipped: ' . implode(', ', $skipped));
    }

    /**
     * @param list<ChainRestoreStep> $steps
     * @param list<string> $notes
     */
    private function failure(BackupChain $chain, array $steps, int $position, ?DateTimeImmutable $until, array $notes, Throwable $e): ChainRestoreException
    {
        $steps[$position] = $steps[$position]->with(ChainRestoreStep::FAILED);

        return new ChainRestoreException(new ChainRestoreReport($chain->ids(), array_values($steps), false, $until, SecretRedactor::fromThrowable($e), $notes), $e);
    }

    /**
     * @param list<ChainRestoreStep> $steps
     */
    private function hasFileSteps(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step->part === ChainRestoreStep::PART_FILES || $step->part === ChainRestoreStep::PART_DELETIONS) && $step->status !== ChainRestoreStep::SKIPPED) {
                return true;
            }
        }

        return false;
    }
}
