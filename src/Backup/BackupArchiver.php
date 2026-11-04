<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use Closure;
use Composer\InstalledVersions;
use DeflateContext;
use JsonException;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpReport;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\InsufficientDiskException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\UnsafeArchiveException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use OutOfBoundsException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

use function array_keys;
use function array_merge;
use function array_reverse;
use function array_unique;
use function array_values;
use function bin2hex;
use function class_exists;
use function deflate_add;
use function deflate_init;
use function dirname;
use function disk_free_space;
use function escapeshellarg;
use function explode;
use function fclose;
use function feof;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function fnmatch;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function glob;
use function hash_file;
use function hash_final;
use function hash_init;
use function hash_update;
use function hrtime;
use function in_array;
use function inflate_add;
use function inflate_init;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function max;
use function mb_substr;
use function preg_match;
use function random_bytes;
use function realpath;
use function rename;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function trim;

use const DATE_ATOM;
use const GLOB_ONLYDIR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_VERSION;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_FINISH;
use const ZLIB_NO_FLUSH;
use const ZLIB_SYNC_FLUSH;

/**
 * Builds an integral site backup: selected paths + optional DB dump + SHA-256 manifest.
 *
 * 1.28+:
 * - per-call {@see BackupOptions} (content, include paths, table rules, format, compression level, heartbeat);
 * - formats `tar.gz` (tar streamed through PHP's gzip, level 0–9), `zip` (ext-zip) and `sql.gz` (database only);
 * - the secrets floor ({@see SecretPathPolicy}): forbidden paths are skipped while collecting and the final file list
 *   is checked again before anything is packed;
 * - every intermediate file lives in an owner-only (0700) directory under `backup.work_dir`, removed in a `finally`;
 *   the finished archive is moved into `storage_dir` only once complete.
 */
final class BackupArchiver
{
    public const MANIFEST_VERSION = 2;

    private const HASH_CHUNK_BYTES = 8 * 1024 * 1024;

    private const STREAM_CHUNK_BYTES = 1024 * 1024;

    private const HEARTBEAT_INTERVAL_NS = 2_000_000_000;

    private const WORK_DIR_PREFIX = 'nowo-site-backup-';

    /** Default extraction limits (EN-I8): total bytes, entries, compression ratio (checked above 64 MiB). */
    public const DEFAULT_EXTRACT_LIMITS = ['max_bytes' => 107_374_182_400, 'max_entries' => 200_000, 'max_ratio' => 200];

    private const RATIO_FLOOR_BYTES = 64 * 1024 * 1024;

    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /** @var array{max_bytes: int, max_entries: int, max_ratio: int} */
    private readonly array $extractLimits;

    /** @var list<string> project-relative runtime state paths never packed into an archive */
    private readonly array $runtimeStateRelativePaths;

    private readonly SecretPathPolicy $secretPolicy;

    /**
     * @param list<string> $includePaths relative to project dir
     * @param list<string> $excludePatterns Finder exclude patterns
     * @param list<string> $runtimeStatePaths restore progress / history / setup progress files (absolute or project-relative);
     *                                        together with $storageDir they are always excluded when inside the project
     * @param string|null $workDir root of the owner-only staging directories (1.28+); null = the system temp dir
     * @param bool $preflightDiskCheck refuse to start when free space is below ~3× the last archive (1.28+)
     * @param string $tarBinary tar executable (tests use a stand-in)
     * @param array{max_bytes?: int, max_entries?: int, max_ratio?: int} $extractLimits `backup.extract_limits` (EN-I8)
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $storageDir,
        private readonly array $includePaths,
        private readonly array $excludePatterns,
        private readonly ?string $databaseDumpCommand,
        private readonly int $processTimeoutSeconds,
        private readonly Filesystem $filesystem = new Filesystem(),
        array $runtimeStatePaths = [],
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly ?BuiltinMysqlDumper $builtinDumper = null,
        ?SecretPathPolicy $secretPolicy = null,
        private readonly ?string $workDir = null,
        private readonly bool $preflightDiskCheck = false,
        private readonly string $tarBinary = 'tar',
        array $extractLimits = [],
    ) {
        $this->extractLimits = [
            'max_bytes'   => (int) ($extractLimits['max_bytes'] ?? self::DEFAULT_EXTRACT_LIMITS['max_bytes']),
            'max_entries' => (int) ($extractLimits['max_entries'] ?? self::DEFAULT_EXTRACT_LIMITS['max_entries']),
            'max_ratio'   => (int) ($extractLimits['max_ratio'] ?? self::DEFAULT_EXTRACT_LIMITS['max_ratio']),
        ];
        $statePaths = [$storageDir, ...$runtimeStatePaths];
        if ($workDir !== null && $workDir !== '') {
            $statePaths[] = $workDir;
        }
        $this->runtimeStateRelativePaths = RuntimeStatePaths::relativeTo($projectDir, $statePaths);
        $this->secretPolicy              = $secretPolicy ?? new SecretPathPolicy();
    }

    /**
     * Project-relative paths of the configured storage dir and runtime state files (excluded from archives).
     *
     * @return list<string>
     */
    public function getRuntimeStateRelativePaths(): array
    {
        return $this->runtimeStateRelativePaths;
    }

    public function getSecretPathPolicy(): SecretPathPolicy
    {
        return $this->secretPolicy;
    }

    public function hasDatabaseDumper(): bool
    {
        return $this->builtinDumper instanceof BuiltinMysqlDumper
            || ($this->databaseDumpCommand !== null && $this->databaseDumpCommand !== '');
    }

    /**
     * @param BackupOptions|string|null $options options (1.28+) or, as in 1.27, the label
     * @param string|null $createdBy 1.27 signature only (with BackupOptions use its createdBy)
     */
    public function create(BackupOptions|string|null $options = null, ?string $createdBy = null): BackupArtifact
    {
        if (!$options instanceof BackupOptions) {
            $options = BackupOptions::fromConfig($options, $createdBy);
        }
        $this->assertSupported($options);

        $this->ensurePrivateDirectory($this->storageDir);
        $workRoot = $this->workRoot();
        $this->removeStaleWorkDirectories(max(3600, 4 * $this->processTimeoutSeconds));
        $this->preflight($workRoot);

        $format   = $options->format;
        $id       = $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $filename = $id . '.' . $format->extension();
        $target   = rtrim($this->storageDir, '/\\') . '/' . $filename;
        // Owner-only staging tree under an unpredictable name (CG L1): it holds the plaintext dump.
        $workDir    = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . $id . '-', $workRoot);
        $payloadDir = $workDir . '/payload';
        $packed     = $workDir . '/archive.' . $format->extension();
        $published  = false;

        $this->filesystem->mkdir($payloadDir, 0o700);
        // EN-I3: lets the next run remove this directory at once if this process dies (SIGTERM / OOM).
        @file_put_contents($workDir . '/.owner', self::ownerTag());

        try {
            $beat     = $this->heartbeat($options->heartbeat);
            $warnings = [];
            $skipped  = 0;
            $outside  = 0;

            $includes  = $options->includePaths ?? $this->includePaths;
            $checksums = $options->includeFiles ? $this->copyIncludedPaths($payloadDir, $includes, $skipped, $outside, $beat) : [];
            $meta      = [
                'project_dir'       => $this->projectDir,
                'includes'          => $options->includeFiles ? array_values($includes) : [],
                'excludes'          => $this->excludePatterns,
                'created_at'        => $this->clock->now()->format(DATE_ATOM),
                'label'             => $options->label,
                'created_by'        => $options->createdBy,
                'php_version'       => PHP_VERSION,
                'format'            => $format->value,
                'compression_level' => $options->compressionLevel,
                'encrypted'         => false,
                'table_rules'       => $options->tableRuleValues(),
                'bundle_version'    => self::bundleVersion(),
                'manifest_version'  => self::MANIFEST_VERSION,
            ];
            if ($options->extraMeta !== []) {
                $meta['extra'] = $options->extraMeta;
            }

            $hasDump = false;
            if ($options->includeDatabase && $this->hasDatabaseDumper()) {
                $dumpRelative = 'database/dump.sql';
                $dumpAbsolute = $payloadDir . '/' . $dumpRelative;
                $this->filesystem->mkdir(dirname($dumpAbsolute), 0o700);
                $report = $this->runDatabaseDump($dumpAbsolute, $options, $beat);
                if ($report instanceof DumpReport) {
                    $meta['dump'] = $report->toManifest();
                    $warnings     = array_merge($warnings, $report->warnings);
                }
                $beat(BackupProgress::PHASE_HASHING);
                $checksums[$dumpRelative] = $this->hashFile($dumpAbsolute, static fn () => $beat());
                $meta['database_dump']    = $dumpRelative;
                $hasDump                  = true;
            }

            $meta['content'] = match (true) {
                $hasDump && $options->includeFiles => 'database_files',
                $hasDump                           => 'database',
                default                            => 'files',
            };
            if ($skipped > 0) {
                $warnings[] = sprintf('%d secret path(s) skipped (secrets floor / backup.forbidden_paths).', $skipped);
            }
            if ($outside > 0) {
                $warnings[] = sprintf('%d link(s) pointing outside the project skipped.', $outside);
            }
            $meta['warnings'] = $warnings;

            // Defence in depth: nothing that matches the floor may reach the packer, whatever include_paths says.
            // EN-I1: the staged tree itself is listed, not only the collected keys.
            $this->secretPolicy->assertNoSecrets(array_values(array_unique([...array_keys($checksums), ...$this->stagedPaths($payloadDir)])));

            $beat(BackupProgress::PHASE_ARCHIVING);
            $tick = static fn () => $beat();
            if ($format === ArchiveFormat::SqlGz) {
                $meta['plaintext_sha256'] = $checksums['database/dump.sql'];
                $this->gzipFile($payloadDir . '/database/dump.sql', $packed, $options->compressionLevel, $tick);
            } else {
                $this->writeManifest($payloadDir, $id, $checksums, $meta);
                if ($format === ArchiveFormat::Zip) {
                    $this->createZip($payloadDir, $packed, $options->compressionLevel, $tick);
                } else {
                    $this->createTarGz($payloadDir, $packed, $options->compressionLevel, $tick);
                }
            }
            // Archives hold the DB dump: owner-only, then published in one step.
            $this->filesystem->chmod($packed, 0o600);
            if (!@rename($packed, $target)) {
                throw new RuntimeException('Unable to move the finished archive into storage_dir.');
            }
            $published = true;

            $artifact = new BackupArtifact(
                id: $id,
                filename: $filename,
                absolutePath: $target,
                createdAt: $this->clock->now(),
                sizeBytes: (int) filesize($target),
                archiveSha256: $this->hashFile($target, $tick),
                checksums: $checksums,
                meta: $meta,
                label: $options->label,
                createdBy: $options->createdBy,
            );

            $this->writeSidecar($artifact);
            $beat(BackupProgress::PHASE_DONE);

            return $artifact;
        } catch (Throwable $e) {
            if ($published) {
                $this->filesystem->remove([$target, $this->sidecarPath($id)]);
            }

            throw $e;
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * Removes staging directories left by killed processes (owner-only dirs with the bundle prefix) under `work_dir`.
     * Does nothing without a configured `work_dir` (the system temp dir is never scanned).
     *
     * @return int number of directories removed
     */
    public function removeStaleWorkDirectories(int $olderThanSeconds): int
    {
        $root = $this->workDir;
        if ($root === null || $root === '' || !is_dir($root)) {
            return 0;
        }

        $limit   = $this->clock->now()->getTimestamp() - $olderThanSeconds;
        $removed = 0;
        foreach (glob(rtrim($root, '/\\') . '/' . self::WORK_DIR_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $mtime = @filemtime($dir);
            if (($mtime !== false && $mtime <= $limit) || self::ownerIsGone($dir)) {
                $this->filesystem->remove($dir);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return list<BackupArtifact>
     */
    public function listArtifacts(): array
    {
        if (!is_dir($this->storageDir)) {
            return [];
        }

        $finder = (new Finder())->files()->in($this->storageDir)->depth(0)->name('*.meta.json')->sortByName(true);
        $list   = [];
        foreach ($finder as $file) {
            $raw = file_get_contents($file->getPathname());
            if ($raw === false) {
                continue;
            }
            try {
                /** @var array<string, mixed> $data */
                $data   = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $list[] = BackupArtifact::fromArray($data);
            } catch (JsonException) {
                continue;
            }
        }

        return array_reverse($list);
    }

    public function find(string $id): ?BackupArtifact
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || str_contains($id, '..')) {
            return null;
        }

        foreach ($this->listArtifacts() as $artifact) {
            if ($artifact->getId() === $id) {
                return $artifact;
            }
        }

        return null;
    }

    public function delete(string $id): bool
    {
        $artifact = $this->find($id);
        if (!$artifact instanceof BackupArtifact) {
            return false;
        }

        // EN-I7: a sidecar only ever deletes an archive inside storage_dir.
        if (!$this->isInsideStorage($artifact->getAbsolutePath())) {
            return false;
        }
        $this->filesystem->remove($artifact->getAbsolutePath());
        $sidecar = $this->sidecarPath($artifact->getId());
        if (is_file($sidecar)) {
            $this->filesystem->remove($sidecar);
        }

        return true;
    }

    /**
     * Verifies archive SHA-256 and every MANIFEST entry after extract to a temp dir (`sql.gz`: the SHA-256 of the
     * decompressed SQL against the sidecar).
     *
     * @param (callable(): void)|null $heartbeat called regularly while hashing and extracting (each hashed chunk,
     *                                           each manifest file, every poll of the running tar process) so a caller
     *                                           can record liveness during long steps
     *
     * @return array{ok: bool, errors: list<string>, checksums: array<string, string>}
     */
    public function verifyIntegrity(BackupArtifact $artifact, ?callable $heartbeat = null): array
    {
        $errors = [];
        if (!is_file($artifact->getAbsolutePath())) {
            return ['ok' => false, 'errors' => ['Archive file missing.'], 'checksums' => []];
        }

        $actualSha = $this->hashFile($artifact->getAbsolutePath(), $heartbeat);
        if ($actualSha !== $artifact->getArchiveSha256()) {
            $errors[] = sprintf('Archive SHA-256 mismatch (expected %s, got %s).', $artifact->getArchiveSha256(), $actualSha);
        }

        $extractDir = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . 'verify-' . $artifact->getId() . '-', $this->workRoot());

        try {
            $this->extractTo($artifact, $extractDir, $heartbeat);

            if ($artifact->getFormat() === ArchiveFormat::SqlGz) {
                $got      = $this->hashFile($extractDir . '/database/dump.sql', $heartbeat);
                $expected = $artifact->getPlaintextSha256();
                if ($expected === null) {
                    $errors[] = 'The sidecar has no plaintext_sha256 for this sql.gz archive.';
                } elseif ($got !== $expected) {
                    $errors[] = 'Checksum mismatch for database/dump.sql';
                }

                return ['ok' => $errors === [], 'errors' => $errors, 'checksums' => ['database/dump.sql' => $got]];
            }

            $manifestFile = $extractDir . '/MANIFEST.json';
            if (!is_file($manifestFile)) {
                $errors[] = 'MANIFEST.json missing inside archive.';

                return ['ok' => false, 'errors' => $errors, 'checksums' => []];
            }

            try {
                /** @var array{checksums?: array<string, string>} $manifest */
                $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $errors[] = 'Invalid MANIFEST.json: ' . $e->getMessage();

                return ['ok' => false, 'errors' => $errors, 'checksums' => []];
            }

            $checksums = $manifest['checksums'] ?? [];
            foreach ($checksums as $relative => $expected) {
                $path = $extractDir . '/' . $relative;
                if (!is_file($path)) {
                    $errors[] = 'Missing file from manifest: ' . $relative;
                    continue;
                }
                $got = $this->hashFile($path, $heartbeat);
                if ($got !== $expected) {
                    $errors[] = sprintf('Checksum mismatch for %s', $relative);
                }
            }

            return ['ok' => $errors === [], 'errors' => $errors, 'checksums' => $checksums];
        } catch (RuntimeException $e) {
            $errors[] = SecretRedactor::redact($e->getMessage());

            return ['ok' => false, 'errors' => $errors, 'checksums' => []];
        } finally {
            $this->filesystem->remove($extractDir);
        }
    }

    /**
     * Extracts an archive of any format into $destinationDir (`sql.gz` → `database/dump.sql`).
     *
     * @param (callable(): void)|null $heartbeat called while tar is running (see verifyIntegrity())
     */
    public function extractTo(BackupArtifact $artifact, string $destinationDir, ?callable $heartbeat = null): void
    {
        $this->filesystem->mkdir($destinationDir);

        match ($artifact->getFormat()) {
            ArchiveFormat::Zip   => $this->extractZip($artifact->getAbsolutePath(), $destinationDir),
            ArchiveFormat::SqlGz => $this->gunzipFile($artifact->getAbsolutePath(), $destinationDir . '/database/dump.sql', $heartbeat),
            ArchiveFormat::TarGz => $this->extractTarGz($artifact->getAbsolutePath(), $destinationDir, $heartbeat),
        };
    }

    /**
     * Zip-slip guard for archive entry names: refuses empty and absolute names, `..` segments, backslashes, NUL and
     * drive letters.
     */
    public static function assertSafeEntryName(string $name): void
    {
        $unsafe = $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', explode('/', $name), true);

        if ($unsafe) {
            throw new RuntimeException(sprintf('Unsafe archive entry name "%s".', mb_substr($name, 0, 120)));
        }
    }

    private function assertSupported(BackupOptions $options): void
    {
        if ($options->tableRules !== [] && !$this->builtinDumper instanceof BuiltinMysqlDumper) {
            throw new InvalidBackupOptionsException('Table rules need backup.database.dumper: builtin.');
        }

        if (!$options->includeFiles && !$this->hasDatabaseDumper()) {
            throw new InvalidBackupOptionsException('A database-only backup needs a configured database dump (backup.database.dumper or backup.database_dump_command).');
        }

        if ($options->format === ArchiveFormat::Zip && !class_exists(ZipArchive::class)) {
            throw new FormatUnavailableException('The zip format needs ext-zip.');
        }
    }

    /**
     * An owner-only (0700) directory under `backup.work_dir` (EN-I6: restore staging). The caller removes it.
     */
    public function createPrivateWorkDirectory(string $prefix): string
    {
        return PrivateTempDirectory::create(self::WORK_DIR_PREFIX . $prefix, $this->workRoot());
    }

    /**
     * @return array{max_bytes: int, max_entries: int, max_ratio: int}
     */
    public function getExtractLimits(): array
    {
        return $this->extractLimits;
    }

    private function ensurePrivateDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        // EN-I3: created owner-only whatever the umask (an existing directory keeps the host's mode).
        $this->filesystem->mkdir($dir, 0o700);
        $this->filesystem->chmod($dir, 0o700);
    }

    private function isInsideStorage(string $path): bool
    {
        $storage = realpath($this->storageDir);
        $real    = realpath($path);

        return $storage !== false && ($real === false ? str_starts_with($path, rtrim($this->storageDir, '/\\') . '/') : str_starts_with($real, $storage . '/'));
    }

    private static function ownerTag(): string
    {
        return (string) gethostname() . ' ' . getmypid();
    }

    /**
     * True when the run directory was written by a process of this host that no longer exists.
     */
    private static function ownerIsGone(string $dir): bool
    {
        $tag = @file_get_contents($dir . '/.owner');
        if (!is_string($tag) || preg_match('/^(\S+) (\d+)$/', $tag, $m) !== 1 || $m[1] !== (string) gethostname() || !is_dir('/proc/self')) {
            return false;
        }

        return (int) $m[2] !== getmypid() && !is_dir('/proc/' . $m[2]);
    }

    /**
     * @return list<string>
     */
    private function stagedPaths(string $payloadDir): array
    {
        $paths = [];
        foreach ((new Finder())->in($payloadDir)->ignoreDotFiles(false) as $entry) {
            if ($entry->isLink()) {
                throw new UnsafeArchiveException('A link reached the staging tree.');
            }
            if ($entry->isFile()) {
                $paths[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($payloadDir) + 1));
            }
        }

        return $paths;
    }

    private function workRoot(): string
    {
        if ($this->workDir === null || $this->workDir === '') {
            return rtrim(sys_get_temp_dir(), '/\\');
        }

        $root = rtrim($this->workDir, '/\\');
        // EN-I3: never a symlink, always owned by this process' user.
        if (is_link($root)) {
            throw new RuntimeException('backup.work_dir must not be a symbolic link.');
        }
        $this->filesystem->mkdir($root, 0o700);
        if (function_exists('posix_geteuid') && @fileowner($root) !== posix_geteuid()) {
            throw new RuntimeException('backup.work_dir must be owned by the user running the backup.');
        }
        // mkdir() keeps an existing directory's mode: tighten it (work_dir holds plaintext dumps).
        $this->filesystem->chmod($root, 0o700);

        return $root;
    }

    private function preflight(string $workRoot): void
    {
        if (!$this->preflightDiskCheck) {
            return;
        }

        $last   = $this->listArtifacts()[0] ?? null;
        $needed = $last instanceof BackupArtifact ? 3 * $last->getSizeBytes() : 0;
        if ($needed <= 0) {
            return;
        }

        foreach (['work_dir' => $workRoot, 'storage_dir' => $this->storageDir] as $which => $dir) {
            $free = @disk_free_space($dir);
            if ($free !== false && $free < $needed) {
                throw new InsufficientDiskException($which, $needed, (int) $free);
            }
        }
    }

    /**
     * Throttled heartbeat: at most every 2 s, plus each phase change.
     *
     * @param (Closure(BackupProgress): void)|null $heartbeat
     *
     * @return Closure(string=): void
     */
    private function heartbeat(?Closure $heartbeat): Closure
    {
        $last  = 0;
        $phase = '';

        return static function (string $current = '') use ($heartbeat, &$last, &$phase): void {
            if ($heartbeat === null) {
                return;
            }
            $now     = hrtime(true);
            $changed = $current !== '' && $current !== $phase;
            if (!$changed && $now - $last < self::HEARTBEAT_INTERVAL_NS) {
                return;
            }
            if ($changed) {
                $phase = $current;
            }
            $last = $now;
            $heartbeat(new BackupProgress($phase));
        };
    }

    /**
     * Streaming SHA-256 so a heartbeat can run between chunks of a very large archive.
     *
     * @param (callable(): void)|null $heartbeat
     */
    private function hashFile(string $path, ?callable $heartbeat): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        try {
            $context = hash_init('sha256');
            while (!feof($handle)) {
                hash_update($context, (string) fread($handle, self::HASH_CHUNK_BYTES));
                if ($heartbeat !== null) {
                    $heartbeat();
                }
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string> $includePaths
     * @param Closure(string=): void $beat
     *
     * @return array<string, string>
     */
    private function copyIncludedPaths(string $payloadDir, array $includePaths, int &$skipped, int &$outside, Closure $beat): array
    {
        $checksums = [];
        $project   = rtrim($this->projectDir, '/\\');
        $verdicts  = ['secret' => 0, 'outside' => 0];
        $floor     = null;
        $beat(BackupProgress::PHASE_COPYING);

        foreach ($this->resolveIncludePaths($includePaths) as $relative) {
            // '' = project root (entire tree minus exclude_patterns)
            $source = $relative === '' ? $project : $project . '/' . $relative;
            if (!file_exists($source)) {
                continue;
            }

            if (is_file($source)) {
                if ($this->isExcludedRelative($relative)) {
                    continue;
                }
                $verdict = $this->classify($relative, $source, $floor);
                if ($verdict !== null) {
                    ++$verdicts[$verdict];
                    continue;
                }
                $checksums[$relative] = $this->copyOne($source, $payloadDir . '/' . $relative);
                continue;
            }

            if (!is_dir($source) || !is_readable($source)) {
                continue;
            }

            $finder = (new Finder())->files()->in($source)->ignoreDotFiles(false);
            foreach ($this->excludePatterns as $pattern) {
                $finder->notPath($pattern);
            }

            foreach ($finder as $file) {
                $full     = $file->getPathname();
                $relInner = substr($full, strlen($source) + 1);
                $relInner = str_replace('\\', '/', $relInner);
                $relPath  = $relative === '' ? $relInner : $relative . '/' . $relInner;
                if ($this->isExcludedRelative($relPath)) {
                    continue;
                }
                $verdict = $this->classify($relPath, $full, $floor);
                if ($verdict !== null) {
                    ++$verdicts[$verdict];
                    continue;
                }
                $checksums[$relPath] = $this->copyOne($full, $payloadDir . '/' . $relPath);
                $beat();
            }
        }

        $skipped += $verdicts['secret'];
        $outside += $verdicts['outside'];

        return $checksums;
    }

    private function copyOne(string $source, string $dest): string
    {
        $this->filesystem->mkdir(dirname($dest), 0o700);
        $this->filesystem->copy($source, $dest, true);

        return (string) hash_file('sha256', $dest);
    }

    /**
     * Why a collected file must not be archived (EN-L1 / EN-L2): `secret` when its project path, its real path (any
     * symlink in its path resolved) or its inode (hard link) matches the floor / forbidden paths; `outside` when its
     * real path leaves the project. null = archive it.
     */
    /**
     * @param array<string, true>|null $floor floor inodes, built on the first hard-linked file of this run
     */
    private function classify(string $relative, string $absolute, ?array &$floor): ?string
    {
        if ($this->secretPolicy->isForbidden($relative)) {
            return 'secret';
        }

        $real    = realpath($absolute);
        $project = realpath($this->projectDir);
        if ($real === false || $project === false) {
            return 'secret';
        }
        if (!str_starts_with($real, $project . '/')) {
            return 'outside';
        }
        if ($this->secretPolicy->isForbidden(substr($real, strlen($project) + 1))) {
            return 'secret';
        }

        $stat = @stat($real);
        if ($stat !== false && $stat['nlink'] > 1) {
            $floor ??= $this->floorInodes($project);
        }
        if ($stat !== false && $stat['nlink'] > 1 && isset($floor[$stat['dev'] . ':' . $stat['ino']])) {
            return 'secret';
        }

        return null;
    }

    /**
     * dev:inode of every floor file of the project (built once per backup, only when a hard-linked file shows up).
     *
     * @return array<string, true>
     */
    private function floorInodes(string $project): array
    {
        $inodes = [];
        $finder = (new Finder())->files()->in($project)->ignoreDotFiles(false)->ignoreVCS(true)
            ->exclude(['vendor', 'node_modules', 'var/cache', 'var/log']);
        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($project) + 1));
            if (!$this->secretPolicy->isForbidden($relative)) {
                continue;
            }
            $stat = @stat($file->getPathname());
            if ($stat !== false) {
                $inodes[$stat['dev'] . ':' . $stat['ino']] = true;
            }
        }

        return $inodes;
    }

    /**
     * Empty include_paths or "." → entire project root (paths without "./" prefix).
     * Omitting the config key still uses Symfony defaults (selective list), not this.
     *
     * @param list<string> $includePaths
     *
     * @return list<string> relative paths; '' means project root
     */
    private function resolveIncludePaths(array $includePaths): array
    {
        if ($includePaths === []) {
            return [''];
        }

        $normalized = [];
        foreach ($includePaths as $relative) {
            $relative = trim(str_replace('\\', '/', $relative), '/');
            if ($relative === '' || $relative === '.') {
                return [''];
            }
            // EN-L1: backup.include_paths from config is checked like per-call includePaths.
            if (in_array('..', explode('/', $relative), true)) {
                throw new InvalidBackupOptionsException('backup.include_paths entries must not contain "..".');
            }
            $normalized[] = $relative;
        }

        return array_values(array_unique($normalized));
    }

    private function isExcludedRelative(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        // Always protect backup storage from nesting into the archive
        if (str_starts_with($relative, 'var/site-backup/') || str_contains($relative, '/var/site-backup/')) {
            return true;
        }

        // Configured storage_dir / work_dir / progress / history files (may live outside var/site-backup/)
        if (RuntimeStatePaths::matches($relative, $this->runtimeStateRelativePaths)) {
            return true;
        }

        foreach ($this->excludePatterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);
            if ($pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $relative) || fnmatch('*/' . ltrim($pattern, '/'), $relative)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Closure(string=): void $beat
     */
    private function runDatabaseDump(string $dumpAbsolute, BackupOptions $options, Closure $beat): ?DumpReport
    {
        $beat(BackupProgress::PHASE_DUMPING);
        $tick = static fn () => $beat();

        if ($this->builtinDumper instanceof BuiltinMysqlDumper) {
            return $this->builtinDumper->dump($dumpAbsolute, $options->tableRules, $options->allowUnsafeTableRules, $tick);
        }

        $command = (string) $this->databaseDumpCommand;

        // Pre-create the dump owner-only: the shell redirect truncates it and keeps the 0600 mode (CG L1).
        $this->filesystem->touch($dumpAbsolute);
        $this->filesystem->chmod($dumpAbsolute, 0o600);
        $process = Process::fromShellCommandline($command . ' > ' . escapeshellarg($dumpAbsolute));

        try {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            ProcessDeadline::run($process, $this->processTimeoutSeconds, $tick);
        } catch (ProcessTimedOutException) {
            // Not chained: Symfony's message embeds the command line, which may carry credentials.
            throw new DumpFailedException(sprintf('Database dump timed out after %d seconds (process_timeout).', $this->processTimeoutSeconds));
        }
        if (!$process->isSuccessful()) {
            throw new DumpFailedException('Database dump failed: ' . mb_substr(SecretRedactor::redact($process->getErrorOutput()), 0, 500));
        }

        return null;
    }

    /**
     * @param array<string, string> $checksums
     * @param array<string, mixed> $meta
     */
    private function writeManifest(string $payloadDir, string $id, array $checksums, array $meta): void
    {
        $manifest = [
            'version'   => self::MANIFEST_VERSION,
            'id'        => $id,
            'checksums' => $checksums,
            'meta'      => $meta,
        ];

        $manifestPath = $payloadDir . '/MANIFEST.json';
        try {
            file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to write MANIFEST.json: ' . $e->getMessage(), 0, $e);
        }
        $this->filesystem->chmod($manifestPath, 0o600);
    }

    /**
     * `tar -cf -` streamed through PHP's gzip: any level 0–9, constant memory, works with GNU tar, bsdtar and
     * BusyBox tar. A failed or timed-out tar leaves no file behind.
     *
     * @param (callable(): void)|null $tick
     */
    private function createTarGz(string $payloadDir, string $target, int $level = 6, ?callable $tick = null): void
    {
        $out = @fopen($target, 'x');
        if ($out === false) {
            throw new RuntimeException('tar create failed: unable to create the archive file.');
        }
        $this->filesystem->chmod($target, 0o600);

        $process = new Process([$this->tarBinary, '-cf', '-', '-C', $payloadDir, '.']);
        $process->setTimeout((float) $this->processTimeoutSeconds);
        $process->setIdleTimeout((float) $this->processTimeoutSeconds);
        $deflate = self::deflateContext($level);

        try {
            $process->start();
            foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                fwrite($out, (string) deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
                if ($tick !== null) {
                    $tick();
                }
            }
            $process->wait();
            fwrite($out, (string) deflate_add($deflate, '', ZLIB_FINISH));
        } catch (ProcessTimedOutException $e) {
            fclose($out);
            $this->filesystem->remove($target);

            throw $e;
        }
        fclose($out);

        if (!$process->isSuccessful()) {
            $this->filesystem->remove($target);

            throw new RuntimeException('tar create failed: ' . mb_substr(trim($process->getErrorOutput()), 0, 500));
        }
    }

    /**
     * @param (callable(): void)|null $tick
     */
    private function createZip(string $payloadDir, string $target, int $level, ?callable $tick): void
    {
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('zip create failed: unable to open the archive.');
        }

        $finder = (new Finder())->files()->in($payloadDir)->ignoreDotFiles(false)->sortByName();
        foreach ($finder as $file) {
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($payloadDir) + 1));
            $zip->addFile($file->getPathname(), $name);
            if ($level === 0) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            } else {
                $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, $level);
            }
        }

        if ($tick !== null) {
            $zip->registerProgressCallback(0.05, static function () use ($tick): void {
                $tick();
            });
        }

        if (!$zip->close()) {
            $this->filesystem->remove($target);

            throw new RuntimeException('zip create failed.');
        }
    }

    /**
     * @param (callable(): void)|null $tick
     */
    private function gzipFile(string $source, string $target, int $level, ?callable $tick): void
    {
        $in  = @fopen($source, 'r');
        $out = @fopen($target, 'x');
        if ($in === false || $out === false) {
            throw new RuntimeException('gzip failed: unable to open the dump or the archive.');
        }
        $this->filesystem->chmod($target, 0o600);

        try {
            $deflate = self::deflateContext($level);
            while (!feof($in)) {
                fwrite($out, (string) deflate_add($deflate, (string) fread($in, self::STREAM_CHUNK_BYTES), ZLIB_NO_FLUSH));
                if ($tick !== null) {
                    $tick();
                }
            }
            fwrite($out, (string) deflate_add($deflate, '', ZLIB_FINISH));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @param (callable(): void)|null $tick
     */
    private function gunzipFile(string $source, string $target, ?callable $tick): void
    {
        $this->filesystem->mkdir(dirname($target), 0o700);
        $in  = @fopen($source, 'r');
        $out = @fopen($target, 'w');
        if ($in === false || $out === false) {
            throw new RuntimeException('gunzip failed: unable to open the archive or the target.');
        }
        $this->filesystem->chmod($target, 0o600);

        $limit   = $this->decompressedLimit((int) @filesize($source));
        $written = 0;

        try {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP) ?: throw new RuntimeException('gunzip failed: zlib is not available.');
            while (!feof($in)) {
                $plain = @inflate_add($inflate, (string) fread($in, self::STREAM_CHUNK_BYTES), ZLIB_SYNC_FLUSH);
                if ($plain === false) {
                    throw new RuntimeException('gunzip failed: corrupt sql.gz archive.');
                }
                $written += strlen($plain);
                if ($written > $limit) {
                    throw new UnsafeArchiveException('The decompressed size exceeds backup.extract_limits.');
                }
                fwrite($out, $plain);
                if ($tick !== null) {
                    $tick();
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Refuses unsafe names, symlinks and encrypted entries (zip-slip guard), then extracts.
     */
    private function extractZip(string $archive, string $destination): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new FormatUnavailableException('The zip format needs ext-zip.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('zip extract failed: unable to open the archive.');
        }

        try {
            if ($zip->numFiles > $this->extractLimits['max_entries']) {
                throw new UnsafeArchiveException('The archive has more entries than backup.extract_limits allows.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new RuntimeException('zip extract failed: unreadable entry.');
                }
                self::assertSafeEntryName($stat['name']);
                $total += (int) $stat['size'];
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw new FormatUnavailableException('Encrypted zip archives need SiteBackupBundle 1.29 or later.');
                }
                $opsys = 0;
                $attr  = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0o170000) === 0o120000) {
                    throw new RuntimeException('zip extract failed: symbolic links are not allowed in a backup archive.');
                }
            }

            $this->assertWithinLimits($total, (int) @filesize($archive));

            if (!$zip->extractTo($destination)) {
                throw new RuntimeException('zip extract failed.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param (callable(): void)|null $heartbeat called before start and on every poll while tar runs
     */
    private function extractTarGz(string $archive, string $destination, ?callable $heartbeat = null): void
    {
        $this->assertSafeTar($archive, $heartbeat);

        $process = new Process([$this->tarBinary, '-xzf', $archive, '-C', $destination]);
        ProcessDeadline::run($process, $this->processTimeoutSeconds, $heartbeat);
        if (!$process->isSuccessful()) {
            throw new RuntimeException('tar extract failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * EN-L3 / EN-I8: lists the tar first and refuses link, hard-link and device entries, unsafe names, and archives
     * over the size / entry / ratio limits.
     *
     * @param (callable(): void)|null $heartbeat
     */
    private function assertSafeTar(string $archive, ?callable $heartbeat): void
    {
        $listing = new Process([$this->tarBinary, '-tvzf', $archive]);
        ProcessDeadline::run($listing, $this->processTimeoutSeconds, $heartbeat);
        if (!$listing->isSuccessful()) {
            throw new UnsafeArchiveException('The tar.gz archive cannot be listed.');
        }

        $entries = 0;
        $total   = 0;
        foreach (explode("\n", trim($listing->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }
            if (!in_array($line[0], ['-', 'd'], true) || str_contains($line, ' link to ') || str_contains($line, ' -> ')) {
                throw new UnsafeArchiveException('Links and special files are not allowed in a backup archive.');
            }
            if (++$entries > $this->extractLimits['max_entries']) {
                throw new UnsafeArchiveException('The archive has more entries than backup.extract_limits allows.');
            }
            if (preg_match('/^\S+\s+\S+\s+(\d+)\s/', $line, $m) === 1) {
                $total += (int) $m[1];
            }
        }
        $this->assertWithinLimits($total, (int) @filesize($archive));

        $names = new Process([$this->tarBinary, '-tzf', $archive]);
        ProcessDeadline::run($names, $this->processTimeoutSeconds, $heartbeat);
        foreach (explode("\n", trim($names->getOutput())) as $name) {
            $name = rtrim((string) preg_replace('#^(\./)+#', '', $name), '/');
            if ($name === '' || $name === '.') {
                continue;
            }
            try {
                self::assertSafeEntryName($name);
            } catch (RuntimeException $e) {
                throw new UnsafeArchiveException($e->getMessage(), 0, $e);
            }
        }
    }

    private function assertWithinLimits(int $total, int $compressed): void
    {
        if ($total > $this->decompressedLimit($compressed)) {
            throw new UnsafeArchiveException('The decompressed size exceeds backup.extract_limits.');
        }
    }

    private function decompressedLimit(int $compressed): int
    {
        $byRatio = max(self::RATIO_FLOOR_BYTES, $compressed * $this->extractLimits['max_ratio']);

        return min($this->extractLimits['max_bytes'], $byRatio);
    }

    private function writeSidecar(BackupArtifact $artifact): void
    {
        $path = $this->sidecarPath($artifact->getId());
        try {
            file_put_contents(
                $path,
                json_encode($artifact->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to write backup sidecar: ' . $e->getMessage(), 0, $e);
        }
        $this->filesystem->chmod($path, 0o600);
    }

    private function sidecarPath(string $id): string
    {
        return rtrim($this->storageDir, '/\\') . '/' . $id . '.meta.json';
    }

    private static function deflateContext(int $level): DeflateContext
    {
        return deflate_init(ZLIB_ENCODING_GZIP, ['level' => $level]) ?: throw new RuntimeException('gzip failed: zlib is not available.');
    }

    private static function bundleVersion(): string
    {
        try {
            return (string) InstalledVersions::getPrettyVersion('nowo-tech/site-backup-bundle');
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }
}
