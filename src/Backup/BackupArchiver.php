<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Backup;

use Closure;
use Composer\InstalledVersions;
use DeflateContext;
use InvalidArgumentException;
use JsonException;
use Nowo\SiteBackupBundle\Crypto\AgeBinaryEncryptor;
use Nowo\SiteBackupBundle\Crypto\ArchiveEncryptorInterface;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\DumpReport;
use Nowo\SiteBackupBundle\Database\TableStatsCollector;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Exception\CorruptArchiveException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\FormatUnavailableException;
use Nowo\SiteBackupBundle\Exception\InsufficientDiskException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\UnsafeArchiveException;
use Nowo\SiteBackupBundle\Exception\WrongPasswordException;
use Nowo\SiteBackupBundle\Incremental\BinlogCaptureInterface;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\BinlogServerInterface;
use Nowo\SiteBackupBundle\Incremental\CapturedBinlog;
use Nowo\SiteBackupBundle\Incremental\ChainLink;
use Nowo\SiteBackupBundle\Incremental\ChainPaths;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Incremental\IncrementalArtifact;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Model\BackupProgress;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Nowo\SiteBackupBundle\Signing\BackupSigner;
use OutOfBoundsException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_reverse;
use function array_unique;
use function array_values;
use function bin2hex;
use function class_exists;
use function count;
use function deflate_add;
use function deflate_init;
use function dirname;
use function disk_free_space;
use function error_clear_last;
use function error_get_last;
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
use function hash_equals;
use function hash_final;
use function hash_init;
use function hash_update;
use function hexdec;
use function hrtime;
use function in_array;
use function inflate_add;
use function inflate_init;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function max;
use function mb_substr;
use function min;
use function mkdir;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function realpath;
use function rename;
use function rmdir;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function touch;
use function trim;
use function unlink;

use const DATE_ATOM;
use const GLOB_ONLYDIR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_INT_MAX;
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

    public const ENCRYPTION_ZIP_AES = 'zip-aes256-ae2';

    private const HASH_CHUNK_BYTES = 8 * 1024 * 1024;

    private const STREAM_CHUNK_BYTES = 1024 * 1024;

    /** EN-I11: compressed bytes fed to one inflate_add() call (deflate expands at most ~1032×, so ≤ ~16 MiB out). */
    private const INFLATE_SLICE_BYTES = 16 * 1024;

    private const HEARTBEAT_INTERVAL_NS = 2_000_000_000;

    private const WORK_DIR_PREFIX = 'nowo-site-backup-';

    /** Default extraction limits (EN-I8): total bytes, entries, compression ratio (checked above 64 MiB). */
    public const DEFAULT_EXTRACT_LIMITS = ['max_bytes' => 107_374_182_400, 'max_entries' => 200_000, 'max_ratio' => 200];

    private const RATIO_FLOOR_BYTES = 64 * 1024 * 1024;

    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /** 1.36+: chain metadata kept by an import. */
    private const CHAIN_META_KEYS = [
        'type', 'chain_id', 'parent_id', 'binlog_file', 'binlog_position', 'server_uuid', 'gtid_executed', 'binlog_database',
        'binlog_from', 'binlog_files', 'first_event_at', 'last_event_at', 'binlog_captured_at', 'file_manifest',
        'file_manifest_sha256', 'base_manifest_sha256', 'files_changed', 'files_deleted', 'binlog_capture', 'binlog_events',
        'snapshot_at',
    ];

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
        private readonly ?ArchiveEncryptorInterface $encryptor = null,
        private readonly ?BackupSigner $signer = null,
        private readonly ?TableStatsCollector $statsCollector = null,
        array $extractLimits = [],
        private readonly ?BinlogCaptureInterface $binlogCapture = null,
        private readonly ?BinlogServerInterface $binlogServer = null,
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
        $ageWrap  = $options->password !== null && $format !== ArchiveFormat::Zip;
        $filename = $id . '.' . $format->extension() . ($ageWrap ? $this->requireEncryptor()->suffix() : '');
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

            $includes = $options->includePaths ?? $this->includePaths;
            // 1.36+: chain backups track every collected file (size, mtime, SHA-256); an incremental archives the delta.
            $track   = $options->includeFiles && ($options->recordFileManifest || $options->baseManifest instanceof FileManifest);
            $scope   = FileManifest::scopeOf(array_values($includes), array_values($this->excludePatterns));
            $entries = [];
            if ($options->baseManifest instanceof FileManifest && $options->baseManifest->scope !== $scope) {
                throw new InvalidBackupOptionsException('The include paths or exclude patterns changed since the base manifest: take a full backup.');
            }
            // Files modified from 2 s before the start on may change again within the same mtime second: not trusted.
            $racyFrom  = $this->clock->now()->getTimestamp() - 2;
            $reserved  = 0;
            $checksums = $options->includeFiles ? $this->copyIncludedPaths($payloadDir, $includes, $skipped, $outside, $beat, $track ? $options->baseManifest : null, $track, $entries, $racyFrom, $reserved) : [];
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
                'encrypted'         => $options->password !== null,
                'encryption'        => $options->password === null ? null : ($ageWrap ? $this->requireEncryptor()->name() : self::ENCRYPTION_ZIP_AES),
                'key_id'            => $options->keyId,
                'table_rules'       => $options->tableRuleValues(),
                'bundle_version'    => self::bundleVersion(),
                'manifest_version'  => self::MANIFEST_VERSION,
            ];
            if ($options->extraMeta !== []) {
                $meta['extra'] = $options->extraMeta;
            }

            if ($options->isChainMember()) {
                $meta['type']      = $options->isIncremental() ? ChainLink::TYPE_INCREMENTAL : ChainLink::TYPE_FULL;
                $meta['chain_id']  = $options->chainId ?? $id;
                $meta['parent_id'] = $options->parentId;
            }
            if ($track) {
                $meta += $this->recordFiles($payloadDir, new FileManifest($entries, $scope), $options->baseManifest, $checksums);
            }

            $hasDump   = false;
            $hasBinlog = false;
            if ($options->includeDatabase && $options->binlogFrom instanceof BinlogCoordinates) {
                $meta += $this->captureBinlogs($payloadDir, $options->binlogFrom, $checksums, $beat);
                $hasBinlog = true;
            } elseif ($options->includeDatabase && $this->hasDatabaseDumper()) {
                $dumpRelative = 'database/dump.sql';
                $dumpAbsolute = $payloadDir . '/' . $dumpRelative;
                $this->filesystem->mkdir(dirname($dumpAbsolute), 0o700);
                $report = $this->runDatabaseDump($dumpAbsolute, $options, $beat);
                if ($report instanceof DumpReport) {
                    $meta['dump'] = $report->toManifest();
                    $warnings     = array_merge($warnings, $report->warnings);
                    if ($report->binlogCoordinates !== null) {
                        // PP-L8: the snapshot is at most this recent; a point in time before it cannot be restored.
                        $meta += $this->dumpCoordinates($report->binlogCoordinates) + ['snapshot_at' => $this->clock->now()->format(DATE_ATOM), 'dump_routines' => $options->dumpRoutines];
                    }
                }
                $beat(BackupProgress::PHASE_HASHING);
                $checksums[$dumpRelative] = $this->hashFile($dumpAbsolute, static fn () => $beat());
                $meta['database_dump']    = $dumpRelative;
                $hasDump                  = true;
            }

            $hasDatabase     = $hasDump || $hasBinlog;
            $meta['content'] = match (true) {
                $hasDatabase && $options->includeFiles => 'database_files',
                $hasDatabase                           => 'database',
                default                                => 'files',
            };
            if ($hasDump && $options->recordTableStats && $this->statsCollector instanceof TableStatsCollector) {
                $meta['table_stats'] = self::expectedTableStats($this->statsCollector->collect(), $options->tableRuleValues());
            }
            if ($skipped > 0) {
                $warnings[] = sprintf('%d secret path(s) skipped (secrets floor / backup.forbidden_paths).', $skipped);
            }
            if ($outside > 0) {
                $warnings[] = sprintf('%d link(s) pointing outside the project skipped.', $outside);
            }
            if ($reserved > 0) {
                $warnings[] = sprintf('%d file(s) at a path the archive reserves (MANIFEST.json, database/dump.sql, .nowo-site-backup/) skipped.', $reserved);
            }
            if ($options->password !== null && !$ageWrap && $options->password->estimatedBits() < ArchivePassword::WEAK_ZIP_BITS) {
                $warnings[] = 'The archive password looks weak for zip AES (PBKDF2-SHA1 x 1000): prefer a generated password of 20+ characters.';
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
                    $this->createZip($payloadDir, $packed, $options->compressionLevel, $tick, $options->password);
                } else {
                    $this->createTarGz($payloadDir, $packed, $options->compressionLevel, $tick);
                }
            }
            if ($ageWrap) {
                $encrypted = $packed . $this->requireEncryptor()->suffix();
                $this->requireEncryptor()->encrypt($packed, $encrypted, $options->password, $tick);
                $this->filesystem->remove($packed);
                $packed = $encrypted;
            }
            // Archives hold the DB dump: owner-only, then published in one step.
            $this->filesystem->chmod($packed, 0o600);
            if (!@rename($packed, $target)) {
                throw new RuntimeException('Unable to move the finished archive into storage_dir.');
            }
            $published = true;

            $artifact = $this->signed(new BackupArtifact(
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
            ));

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
     * Imports an archive made elsewhere (a downloaded remote backup, another instance) into `storage_dir` (1.29+).
     *
     * The format is detected from the content (zip, tar.gz, sql.gz), encrypted zip entries are decrypted with
     * $password, entry names / symlinks / ZipCrypto are refused, MANIFEST checksums are verified, secret paths are
     * dropped, and the result is stored as a plain `tar.gz` in the bundle layout, so `restore()` and the panel can use it.
     * The stored copy is plaintext (`0600`, owner-only, owner decision Q-O6): delete it after the restore.
     *
     * @param array{original_parent_id: string, parent_id: string}|null $chainLink 1.36+: the archive is the next
     *                                                                             incremental of a chain being imported:
     *                                                                             its original parent and the new id of
     *                                                                             that parent here
     *
     * @throws CorruptArchiveException|FormatUnavailableException|UnsafeArchiveException|WrongPasswordException
     */
    public function import(string $path, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $createdBy = null, ?ImportOptions $options = null, ?array $chainLink = null): BackupArtifact
    {
        $options ??= new ImportOptions();
        if (!is_file($path) || !is_readable($path)) {
            throw new CorruptArchiveException('The archive to import is missing or not readable.');
        }
        // EO-M2: a required signature is checked against the archive bytes before anything is unpacked.
        $signatureError = $this->signatureError($options->sidecar, $this->hashFile($path, null), $options->requireSignature);
        if ($signatureError !== null) {
            throw new CorruptArchiveException($signatureError, CorruptArchiveException::SIGNATURE);
        }

        $this->filesystem->mkdir($this->storageDir);
        $workRoot   = $this->workRoot();
        $id         = $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $workDir    = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . 'import-' . $id . '-', $workRoot);
        $payloadDir = $workDir . '/payload';
        $packed     = $workDir . '/archive.tar.gz';
        $target     = rtrim($this->storageDir, '/\\') . '/' . $id . '.tar.gz';
        $published  = false;
        $this->filesystem->mkdir($payloadDir, 0o700);

        try {
            $sourceSha = $this->hashFile($path, null);
            $unpacked  = $this->unpack($path, $password, $workDir, $payloadDir, $options->allowPlaintext);
            $source    = $unpacked['source'];
            $dropped   = $unpacked['dropped'];
            $checksums = $unpacked['checksums'];
            $original  = $unpacked['original'];

            $warnings = [];
            if ($dropped !== []) {
                $warnings[] = sprintf('%d secret path(s) dropped from the imported archive.', count($dropped));
            }
            $hasDump = isset($checksums['database/dump.sql']);
            $files   = array_filter(array_keys($checksums), static fn (string $p): bool => $p !== 'database/dump.sql');
            $meta    = [
                'project_dir'       => $this->projectDir,
                'includes'          => is_array($original['includes'] ?? null) ? $original['includes'] : [],
                'excludes'          => is_array($original['excludes'] ?? null) ? $original['excludes'] : [],
                'created_at'        => $this->clock->now()->format(DATE_ATOM),
                'label'             => is_string($original['label'] ?? null) ? $original['label'] : null,
                'created_by'        => $createdBy,
                'php_version'       => PHP_VERSION,
                'format'            => ArchiveFormat::TarGz->value,
                'compression_level' => 6,
                'encrypted'         => false,
                'encryption'        => null,
                'key_id'            => null,
                'table_rules'       => is_array($original['table_rules'] ?? null) ? $original['table_rules'] : [],
                'bundle_version'    => self::bundleVersion(),
                'manifest_version'  => self::MANIFEST_VERSION,
                'content'           => match (true) {
                    $hasDump && $files !== [] => 'database_files',
                    $hasDump                  => 'database',
                    default                   => 'files',
                },
                'imported_from' => [
                    'format' => $source->value,
                    'sha256' => $sourceSha,
                    // EO-M1: from the container itself, never from the (attacker-controllable) MANIFEST.
                    'encrypted'   => $unpacked['encryption'] !== null,
                    'encryption'  => $unpacked['encryption'],
                    'key_id'      => is_string($original['key_id'] ?? null) ? $original['key_id'] : null,
                    'original_id' => is_string($original['id'] ?? null) ? $original['id'] : null,
                    'created_at'  => is_string($original['created_at'] ?? null) ? $original['created_at'] : null,
                ],
                'warnings'             => $warnings,
                'delete_after_restore' => $options->deleteAfterRestore,
            ];
            if ($hasDump) {
                $meta['database_dump'] = 'database/dump.sql';
            }
            if (is_array($original['table_stats'] ?? null)) {
                $meta['table_stats'] = $original['table_stats'];
            }
            $meta = $this->importedChainMeta($original, $chainLink) + $meta;

            $this->secretPolicy->assertNoSecrets(array_keys($checksums));
            $this->writeManifest($payloadDir, $id, $checksums, $meta);
            $this->createTarGz($payloadDir, $packed);
            $this->filesystem->chmod($packed, 0o600);
            if (!@rename($packed, $target)) {
                throw new RuntimeException('Unable to move the imported archive into storage_dir.');
            }
            $published = true;

            $artifact = $this->signed(new BackupArtifact(
                id: $id,
                filename: $id . '.tar.gz',
                absolutePath: $target,
                createdAt: $this->clock->now(),
                sizeBytes: (int) filesize($target),
                archiveSha256: $this->hashFile($target, null),
                checksums: $checksums,
                meta: $meta,
                label: $meta['label'],
                createdBy: $createdBy,
            ));
            $this->writeSidecar($artifact);

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
     * The id and meta recorded in an archive's MANIFEST (1.36+): verifies the archive like an import, stores nothing.
     * Used to continue a chain whose parent is only available remotely (the sidecar carries no file manifest).
     *
     * @throws CorruptArchiveException|FormatUnavailableException|UnsafeArchiveException|WrongPasswordException
     *
     * @return array{id: string, meta: array<string, mixed>}
     */
    public function readArchiveMeta(string $path, #[SensitiveParameter] ?ArchivePassword $password = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CorruptArchiveException('The archive is missing or not readable.');
        }
        $workDir = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . 'meta-', $this->workRoot());
        try {
            $original = $this->unpack($path, $password, $workDir, $workDir . '/payload')['original'];
        } finally {
            $this->filesystem->remove($workDir);
        }
        $id = $original['id'] ?? null;
        unset($original['id']);
        if (!is_string($id) || $id === '') {
            throw new CorruptArchiveException('The archive has no id in its MANIFEST.');
        }

        return ['id' => $id, 'meta' => $original];
    }

    /**
     * 1.36+: the chain metadata of an imported archive. A full backup keeps it as is; an incremental is only accepted as
     * the next link of a chain being imported ($chainLink), and its parent id is rewritten to the new id.
     *
     * @param array<string, mixed> $original MANIFEST meta of the archive
     * @param array{original_parent_id: string, parent_id: string}|null $chainLink
     *
     * @return array<string, mixed>
     */
    private function importedChainMeta(array $original, ?array $chainLink): array
    {
        $type = $original['type'] ?? null;
        if ($type !== ChainLink::TYPE_FULL && $type !== ChainLink::TYPE_INCREMENTAL) {
            if ($chainLink !== null) {
                throw new CorruptArchiveException('This archive is not an incremental backup: it cannot continue the chain.');
            }

            return [];
        }
        if ($type === ChainLink::TYPE_INCREMENTAL && $chainLink === null) {
            throw new InvalidBackupOptionsException('An incremental backup is imported together with its chain: pass the full backup and every incremental, oldest first.');
        }
        if ($type === ChainLink::TYPE_FULL && $chainLink !== null) {
            throw new CorruptArchiveException('A full backup cannot continue a chain.');
        }
        if ($chainLink !== null && ($original['parent_id'] ?? null) !== $chainLink['original_parent_id']) {
            throw new CorruptArchiveException('This incremental backup does not continue the previous archive of the chain.');
        }

        $meta = [];
        foreach (self::CHAIN_META_KEYS as $key) {
            if (array_key_exists($key, $original)) {
                $meta[$key] = $original[$key];
            }
        }
        if ($chainLink !== null) {
            $meta['parent_id'] = $chainLink['parent_id'];
        }

        return $meta;
    }

    /**
     * Checks an archive file without storing it (1.29+, `nowo:site-backup:verify --file`): decrypts, verifies the
     * MANIFEST (or the SQL checksum against $sidecar for `sql.gz`), the archive SHA-256 and the signature.
     *
     * @param array<string, mixed>|null $sidecar a `.backup.json` / `.meta.json` of the archive, when available
     */
    public function verifyFile(string $path, #[SensitiveParameter] ?ArchivePassword $password = null, ?string $expectedSha256 = null, ?array $sidecar = null, bool $allowPlaintext = false, ?bool $requireSignature = null): VerifyFileResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return new VerifyFileResult(VerifyFileResult::UNSUPPORTED, ['The archive is missing or not readable.']);
        }

        $sha = $this->hashFile($path, null);
        $expectedSha256 ??= is_string($sidecar['sha256'] ?? null) ? $sidecar['sha256'] : (is_string($sidecar['archive_sha256'] ?? null) ? $sidecar['archive_sha256'] : null);
        if ($expectedSha256 !== null && !hash_equals(strtolower($expectedSha256), $sha)) {
            return new VerifyFileResult(VerifyFileResult::INTEGRITY, ['Archive SHA-256 mismatch.']);
        }

        $signatureError = $this->signatureError($sidecar, $sha, $requireSignature);
        if ($signatureError !== null) {
            return new VerifyFileResult(VerifyFileResult::SIGNATURE, [$signatureError]);
        }

        $workDir = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . 'verify-file-', $this->workRoot());
        try {
            $unpacked = $this->unpack($path, $password, $workDir, $workDir . '/payload', $allowPlaintext);
            $plain    = $sidecar['plaintext_sha256'] ?? null;
            if ($unpacked['source'] === ArchiveFormat::SqlGz && is_string($plain) && $unpacked['checksums']['database/dump.sql'] !== $plain) {
                return new VerifyFileResult(VerifyFileResult::INTEGRITY, ['Checksum mismatch for database/dump.sql']);
            }

            return new VerifyFileResult(VerifyFileResult::OK, [], $unpacked['checksums'], $unpacked['source'], $unpacked['encryption']);
        } catch (WrongPasswordException $e) {
            return new VerifyFileResult(VerifyFileResult::WRONG_PASSWORD, [$e->getMessage()]);
        } catch (CorruptArchiveException $e) {
            return new VerifyFileResult(match ($e->getCode()) {
                CorruptArchiveException::CHECKSUM      => VerifyFileResult::INTEGRITY,
                CorruptArchiveException::NOT_ENCRYPTED => VerifyFileResult::NOT_ENCRYPTED,
                default                                => VerifyFileResult::UNSUPPORTED,
            }, [$e->getMessage()]);
        } catch (RuntimeException $e) {
            return new VerifyFileResult(VerifyFileResult::UNSUPPORTED, [SecretRedactor::redact($e->getMessage())]);
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * Decrypts (age), extracts with the slip / link guards, drops secret paths and verifies the MANIFEST.
     *
     * @return array{source: ArchiveFormat, encryption: ?string, dropped: list<string>, checksums: array<string, string>, original: array<string, mixed>}
     */
    private function unpack(string $path, #[SensitiveParameter] ?ArchivePassword $password, string $workDir, string $payloadDir, bool $allowPlaintext = false): array
    {
        $this->filesystem->mkdir($payloadDir, 0o700);
        $encryption = null;
        $plainPath  = $path;
        if ($this->encryptor instanceof ArchiveEncryptorInterface && $this->encryptor->recognises(self::head($path))) {
            if ($password === null) {
                throw new WrongPasswordException('This archive is encrypted: a password is needed.');
            }
            $plainPath = $workDir . '/decrypted';
            $this->encryptor->decrypt($path, $plainPath, $password);
            $encryption = $this->encryptor->name();
        }

        $source = self::detectFormat($plainPath);
        if ($encryption !== null && $source === ArchiveFormat::Zip) {
            throw new CorruptArchiveException('An age container must hold a tar.gz or sql.gz archive.');
        }
        if ($source === ArchiveFormat::Zip && self::zipEncryption($plainPath) === 'all') {
            $encryption = self::ENCRYPTION_ZIP_AES;
        }
        // EO-M1: with a password, only a fully encrypted container is accepted (no plaintext or mixed downgrade).
        if ($password !== null && $encryption === null && !$allowPlaintext) {
            throw self::plaintextWithPassword();
        }
        match ($source) {
            ArchiveFormat::Zip   => $this->extractZip($plainPath, $payloadDir, $password, $allowPlaintext),
            ArchiveFormat::TarGz => $this->extractTarGzSafely($plainPath, $payloadDir),
            ArchiveFormat::SqlGz => $this->gunzipFile($plainPath, $payloadDir . '/database/dump.sql', null),
        };
        if ($plainPath !== $path) {
            $this->filesystem->remove($plainPath);
        }

        $dropped                = $this->dropSecretPaths($payloadDir);
        [$checksums, $original] = $this->importedChecksums($payloadDir, $source, $dropped);

        return ['source' => $source, 'encryption' => $encryption, 'dropped' => $dropped, 'checksums' => $checksums, 'original' => $original];
    }

    private static function plaintextWithPassword(): CorruptArchiveException
    {
        return new CorruptArchiveException('A password was given but the archive is not encrypted (possible downgrade); pass allowPlaintext to accept it.', CorruptArchiveException::NOT_ENCRYPTED);
    }

    /**
     * `all` (every entry AES-256), `none` or `mixed`, from the zip central directory (EO-M1: every entry is checked).
     */
    public static function zipEncryption(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new CorruptArchiveException('zip extract failed: unable to open the archive.');
        }
        $aes   = 0;
        $plain = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new CorruptArchiveException('zip extract failed: unreadable entry.');
                }
                ($stat['encryption_method'] ?? ZipArchive::EM_NONE) === ZipArchive::EM_AES_256 ? ++$aes : ++$plain;
            }
        } finally {
            $zip->close();
        }

        return match (true) {
            $aes > 0 && $plain > 0 => 'mixed',
            $aes > 0               => 'all',
            default                => 'none',
        };
    }

    /**
     * EO-M2: why a sidecar's signature is not acceptable, or null. Required when $require is true, or by default
     * when `backup.signing.required` is on; a missing sidecar / signature then fails too.
     *
     * @param array<string, mixed>|null $sidecar
     */
    private function signatureError(?array $sidecar, string $sha256, ?bool $require): ?string
    {
        $signer    = $this->signer;
        $required  = $require ?? ($signer instanceof BackupSigner && $signer->isRequired());
        $signed    = is_array($sidecar['meta'] ?? null) ? $sidecar['meta'] : ($sidecar ?? []);
        $signature = $signed['signature'] ?? null;

        if (!is_string($signature) || $signature === '') {
            return $required ? 'Signature missing: a signed sidecar is required (backup.signing.required).' : null;
        }
        if (!$signer instanceof BackupSigner || !$signer->canVerify()) {
            return $required ? 'Signature cannot be checked: no verification key is configured.' : null;
        }
        $fields = BackupSigner::fieldsFromSidecar((array) $sidecar);
        if ($fields['sha256'] !== $sha256 || !$signer->verify($fields, $signature, (string) ($signed['signing_key_id'] ?? ''))) {
            return 'Signature invalid or signing key unknown.';
        }

        return null;
    }

    /**
     * Expected table statistics of a dump: structure-only tables hold no rows, skipped tables are not in the dump.
     *
     * @param array<string, array{rows: int, checksum: ?string}> $stats
     * @param array<string, string> $rules
     *
     * @return array<string, array{rows: int, checksum: ?string}>
     */
    public static function expectedTableStats(array $stats, array $rules): array
    {
        foreach ($rules as $table => $rule) {
            if ($rule === 'skip') {
                unset($stats[$table]);
            } elseif (isset($stats[$table])) {
                $stats[$table] = ['rows' => 0, 'checksum' => '0'];
            }
        }

        return $stats;
    }

    private function signed(BackupArtifact $artifact): BackupArtifact
    {
        if (!$this->signer instanceof BackupSigner || !$this->signer->canSign()) {
            return $artifact;
        }

        $signature = $this->signer->sign(BackupSigner::fieldsFromArtifact($artifact));
        $meta      = $artifact->getMeta() + [
            'checksums_sha256' => BackupSigner::checksumsDigest($artifact->getChecksums()),
            'signature'        => $signature['signature'],
            'signing_key_id'   => $signature['signing_key_id'],
        ];

        return new BackupArtifact(
            $artifact->getId(),
            $artifact->getFilename(),
            $artifact->getAbsolutePath(),
            $artifact->getCreatedAt(),
            $artifact->getSizeBytes(),
            $artifact->getArchiveSha256(),
            $artifact->getChecksums(),
            $meta,
            $artifact->getLabel(),
            $artifact->getCreatedBy(),
        );
    }

    private function requireEncryptor(): ArchiveEncryptorInterface
    {
        if (!$this->encryptor instanceof ArchiveEncryptorInterface) {
            throw new FormatUnavailableException('Password-protected tar.gz / sql.gz archives need the age encryptor (backup.encryption.age).');
        }

        return $this->encryptor;
    }

    private static function head(string $path): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }
        try {
            return (string) fread($handle, 64);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Archive format from the first bytes (zip, tar.gz or sql.gz); the file name is not trusted.
     *
     * @throws CorruptArchiveException|FormatUnavailableException
     */
    public static function detectFormat(string $path): ArchiveFormat
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new CorruptArchiveException('The archive to import is not readable.');
        }

        try {
            $head = (string) fread($handle, self::INFLATE_SLICE_BYTES);
        } finally {
            fclose($handle);
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return ArchiveFormat::Zip;
        }
        if (str_starts_with($head, 'age-encryption.org/v1')) {
            throw new FormatUnavailableException('age archives need a password and the age encryptor (backup.encryption.age).');
        }
        if (str_starts_with($head, "\x1f\x8b")) {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP) ?: throw new RuntimeException('gunzip failed: zlib is not available.');
            $plain   = @inflate_add($inflate, $head, ZLIB_SYNC_FLUSH);
            if ($plain === false) {
                throw new CorruptArchiveException('The archive is not valid gzip data.');
            }

            return substr($plain, 257, 5) === 'ustar' ? ArchiveFormat::TarGz : ArchiveFormat::SqlGz;
        }

        throw new CorruptArchiveException('Unknown archive format (expected zip, tar.gz or sql.gz).');
    }

    /**
     * Lists the tar first: refuses unsafe names, symlinks and hard links, then extracts.
     */
    private function extractTarGzSafely(string $archive, string $destination): void
    {
        $entries = 0;
        $listed  = $this->scanTarListing($archive, null, function (string $line) use (&$entries): void {
            if ($line[0] === 'l' || $line[0] === 'h') {
                throw new CorruptArchiveException('Links are not allowed in an imported archive.');
            }
            $this->countTarEntry($entries);
        });
        if (!$listed) {
            throw new CorruptArchiveException('The tar.gz archive cannot be listed.');
        }

        $names = new Process([$this->tarBinary, '-tzf', $archive]);
        ProcessDeadline::run($names, $this->processTimeoutSeconds);
        foreach (explode("\n", trim($names->getOutput())) as $name) {
            $name = (string) preg_replace('#^(\./)+#', '', $name);
            if ($name === '' || $name === '.') {
                continue;
            }
            try {
                self::assertSafeEntryName(rtrim($name, '/'));
            } catch (RuntimeException $e) {
                throw new CorruptArchiveException($e->getMessage(), 0, $e);
            }
        }

        $this->extractTarGz($archive, $destination);
    }

    /**
     * Removes secret paths from an extracted payload.
     *
     * @return list<string> removed project-relative paths
     */
    private function dropSecretPaths(string $payloadDir): array
    {
        $dropped = [];
        foreach ((new Finder())->files()->in($payloadDir)->ignoreDotFiles(false) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($payloadDir) + 1));
            if ($relative !== 'MANIFEST.json' && $this->secretPolicy->isForbidden($relative)) {
                $this->filesystem->remove($file->getPathname());
                $dropped[] = $relative;
            }
        }

        return $dropped;
    }

    /**
     * Verifies an imported payload: every MANIFEST entry (except dropped secrets) must exist and match.
     *
     * @param list<string> $dropped
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>} checksums, original meta (+ id)
     */
    private function importedChecksums(string $payloadDir, ArchiveFormat $source, array $dropped): array
    {
        if ($source === ArchiveFormat::SqlGz) {
            return [['database/dump.sql' => $this->hashFile($payloadDir . '/database/dump.sql', null)], []];
        }

        $manifestFile = $payloadDir . '/MANIFEST.json';
        if (!is_file($manifestFile)) {
            throw new CorruptArchiveException('MANIFEST.json missing inside the archive.');
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CorruptArchiveException('Invalid MANIFEST.json inside the archive.');
        }
        if (!is_array($manifest) || !is_array($manifest['checksums'] ?? null)) {
            throw new CorruptArchiveException('MANIFEST.json has no checksums.');
        }
        $this->filesystem->remove($manifestFile);

        $checksums = [];
        foreach ($manifest['checksums'] as $relative => $expected) {
            if (!is_string($relative) || !is_string($expected) || in_array($relative, $dropped, true) || $this->secretPolicy->isForbidden($relative)) {
                continue;
            }
            $file = $payloadDir . '/' . $relative;
            if (!is_file($file) || $this->hashFile($file, null) !== $expected) {
                throw new CorruptArchiveException(sprintf('Checksum mismatch or missing file in the imported archive: %s', $relative), CorruptArchiveException::CHECKSUM);
            }
            $checksums[$relative] = $expected;
        }

        $original       = is_array($manifest['meta'] ?? null) ? $manifest['meta'] : [];
        $original['id'] = $manifest['id'] ?? null;

        return [$checksums, $original];
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
     * Verifies archive SHA-256 and signature, then every MANIFEST entry after extract to a temp dir (`sql.gz`: the
     * SHA-256 of the decompressed SQL against the sidecar). An archive that fails the first two is not extracted.
     *
     * @param (callable(): void)|null $heartbeat called regularly while hashing and extracting (each hashed chunk,
     *                                           each manifest file, every poll of the running tar process) so a caller
     *                                           can record liveness during long steps
     *
     * @return array{ok: bool, errors: list<string>, checksums: array<string, string>}
     */
    public function verifyIntegrity(BackupArtifact $artifact, ?callable $heartbeat = null, #[SensitiveParameter] ?ArchivePassword $password = null, bool $allowPlaintext = false): array
    {
        $errors = [];
        if (!is_file($artifact->getAbsolutePath())) {
            return ['ok' => false, 'errors' => ['Archive file missing.'], 'checksums' => []];
        }

        $actualSha = $this->hashFile($artifact->getAbsolutePath(), $heartbeat);
        if ($actualSha !== $artifact->getArchiveSha256()) {
            $errors[] = sprintf('Archive SHA-256 mismatch (expected %s, got %s).', $artifact->getArchiveSha256(), $actualSha);
        }
        $signature = $artifact->getMeta()['signature'] ?? null;
        if (is_string($signature) && $this->signer instanceof BackupSigner && $this->signer->canVerify()
            && !$this->signer->verify(BackupSigner::fieldsFromArtifact($artifact), $signature, (string) ($artifact->getMeta()['signing_key_id'] ?? ''))) {
            $errors[] = 'Signature invalid or signing key unknown.';
        } elseif (!is_string($signature) && $this->signer instanceof BackupSigner && $this->signer->isRequired() && $this->signer->canSign()) {
            // A host that signs every backup (and every import) never has a legitimate unsigned local sidecar.
            // EO-M2: stripping the signature from a sidecar is not a way around a required signature.
            $errors[] = 'Signature missing: a signed sidecar is required (backup.signing.required).';
        }
        // Nothing is unpacked from bytes that are already known not to be the backup.
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'checksums' => []];
        }

        $extractDir = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . 'verify-' . $artifact->getId() . '-', $this->workRoot());

        try {
            $this->extractTo($artifact, $extractDir, $heartbeat, $password, $allowPlaintext);

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
     * With a password, an archive that is not encrypted is refused unless $allowPlaintext. A `zip` never writes over a
     * path that already exists or through a link in the destination, and removes only what it created when it fails:
     * extract into a fresh directory.
     *
     * @param (callable(): void)|null $heartbeat called while tar is running (see verifyIntegrity())
     */
    public function extractTo(BackupArtifact $artifact, string $destinationDir, ?callable $heartbeat = null, #[SensitiveParameter] ?ArchivePassword $password = null, bool $allowPlaintext = false): void
    {
        $this->filesystem->mkdir($destinationDir);

        $source    = $artifact->getAbsolutePath();
        $decrypted = null;
        if (self::isAgeArtifact($artifact)) {
            if ($password === null) {
                throw new WrongPasswordException('This archive is encrypted: a password is needed.');
            }
            $decrypted = PrivateTempDirectory::create(self::WORK_DIR_PREFIX . 'decrypt-', $this->workRoot());
            $source    = $decrypted . '/plain';
            $this->requireEncryptor()->decrypt($artifact->getAbsolutePath(), $source, $password, $heartbeat);
        } elseif ($password !== null && !$allowPlaintext && $artifact->getFormat() !== ArchiveFormat::Zip) {
            throw self::plaintextWithPassword();
        }

        try {
            match ($artifact->getFormat()) {
                ArchiveFormat::Zip   => $this->extractZip($source, $destinationDir, $password, $allowPlaintext),
                ArchiveFormat::SqlGz => $this->gunzipFile($source, $destinationDir . '/database/dump.sql', $heartbeat),
                ArchiveFormat::TarGz => $this->extractTarGz($source, $destinationDir, $heartbeat),
            };
        } finally {
            if ($decrypted !== null) {
                $this->filesystem->remove($decrypted);
            }
        }
    }

    private static function isAgeArtifact(BackupArtifact $artifact): bool
    {
        return ($artifact->getMeta()['encryption'] ?? null) === AgeBinaryEncryptor::NAME || str_ends_with($artifact->getFilename(), '.age');
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

        if ($options->password !== null) {
            if ($options->format === ArchiveFormat::Zip) {
                self::assertZipAesAvailable();
            } else {
                $this->requireEncryptor()->assertAvailable();
            }
        }

        if ($options->recordTableStats && (!$this->statsCollector instanceof TableStatsCollector || !$options->includeDatabase)) {
            throw new InvalidBackupOptionsException('recordTableStats needs the database and the table statistics collector (backup.database.url).');
        }

        // 1.36+: binlog coordinates and captures need the built-in dumper (its database URL) and the binlog services.
        if ($options->recordBinlogCoordinates) {
            $this->binlogServer();
        }
        if ($options->binlogFrom instanceof BinlogCoordinates) {
            $this->binlogCapture();
        }
    }

    private function binlogServer(): BinlogServerInterface
    {
        if (!$this->binlogServer instanceof BinlogServerInterface || !$this->builtinDumper instanceof BuiltinMysqlDumper) {
            throw new InvalidBackupOptionsException('recordBinlogCoordinates needs backup.database.dumper: builtin.');
        }

        return $this->binlogServer;
    }

    private function binlogCapture(): BinlogCaptureInterface
    {
        if (!$this->binlogCapture instanceof BinlogCaptureInterface || !$this->builtinDumper instanceof BuiltinMysqlDumper) {
            throw new InvalidBackupOptionsException('Binary log incrementals need backup.database.dumper: builtin.');
        }

        return $this->binlogCapture;
    }

    /**
     * The chain's database: the built-in dumper's (checked by binlogServer() / binlogCapture()).
     */
    private function databaseName(): string
    {
        return (string) $this->builtinDumper?->databaseName();
    }

    /**
     * 1.36+: `file_manifest` (+ `DELETED.json` and the delta counters for an incremental) of a chain backup.
     *
     * @param array<string, string> $checksums files archived so far; `DELETED.json` is added
     *
     * @return array<string, mixed>
     */
    private function recordFiles(string $payloadDir, FileManifest $manifest, ?FileManifest $base, array &$checksums): array
    {
        $meta = ['file_manifest' => $manifest->toArray(), 'file_manifest_sha256' => $manifest->sha256()];
        if (!$base instanceof FileManifest) {
            return $meta;
        }

        $deleted = $base->deletedIn($manifest);
        $file    = $payloadDir . '/' . ChainPaths::DELETED;
        $this->filesystem->mkdir(dirname($file), 0o700);
        try {
            file_put_contents($file, json_encode($deleted, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to write DELETED.json.', 0, $e);
        }
        $this->filesystem->chmod($file, 0o600);
        $checksums[ChainPaths::DELETED] = $this->hashFile($file, null);

        return $meta + [
            'base_manifest_sha256' => $base->sha256(),
            'files_changed'        => count($checksums) - 1,
            'files_deleted'        => count($deleted),
        ];
    }

    /**
     * 1.36+: the coordinates `--source-data=2` recorded, with this server's UUID, as the start of a chain.
     *
     * @param array{file: string, position: int, gtid: ?string} $parsed
     *
     * @return array<string, mixed>
     */
    private function dumpCoordinates(array $parsed): array
    {
        try {
            $coordinates = new BinlogCoordinates($parsed['file'], $parsed['position'], $this->binlogServer()->serverUuid(), $parsed['gtid']);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('The dump recorded unusable binlog coordinates: ' . $e->getMessage(), 0, $e);
        }

        return $coordinates->toArray() + ['binlog_database' => $this->databaseName()];
    }

    /**
     * 1.36+: the database part of an incremental: the binary logs since $from, under `binlog/` in the payload.
     *
     * @param array<string, string> $checksums
     * @param Closure(string=): void $beat
     *
     * @return array<string, mixed>
     */
    private function captureBinlogs(string $payloadDir, BinlogCoordinates $from, array &$checksums, Closure $beat): array
    {
        $capture = $this->binlogCapture();
        $beat(BackupProgress::PHASE_DUMPING);
        $database = $this->databaseName();
        $captured = $capture->capture($from, $database, static fn () => $beat());

        $meta = [];
        try {
            $this->filesystem->mkdir($payloadDir . '/' . ChainPaths::BINLOG_DIRECTORY, 0o700);
            $files = [];
            foreach ($captured->files as $file) {
                // Filtered: the source logs are described (contiguity) but not archived.
                $files[] = ['name' => $file->name, 'sha256' => $file->sha256, 'size' => $file->sizeBytes];
                if ($captured->mode === IncrementalArtifact::RAW) {
                    $relative = ChainPaths::BINLOG_DIRECTORY . $file->name;
                    $this->filesystem->rename($file->path, $payloadDir . '/' . $relative);
                    $checksums[$relative] = $file->sha256;
                }
            }
            $events = $captured->events;
            if ($events instanceof CapturedBinlog) {
                $this->filesystem->rename($events->path, $payloadDir . '/' . ChainPaths::EVENTS);
                $checksums[ChainPaths::EVENTS] = $events->sha256;
                $meta['binlog_events']         = ['path' => ChainPaths::EVENTS, 'sha256' => $events->sha256, 'size' => $events->sizeBytes, 'dropped_events' => $captured->droppedEvents];
                $index                         = $captured->eventsIndex;
                if ($index instanceof CapturedBinlog) {
                    $this->filesystem->rename($index->path, $payloadDir . '/' . ChainPaths::EVENTS_INDEX);
                    $checksums[ChainPaths::EVENTS_INDEX]   = $index->sha256;
                    $meta['binlog_events']['index_sha256'] = $index->sha256;
                }
            }
        } finally {
            $capture->discard($captured);
        }

        return $captured->to->toArray() + $meta + [
            'binlog_capture'     => $captured->mode,
            'binlog_from'        => $captured->from->toArray(),
            'binlog_database'    => $database,
            'binlog_files'       => $files,
            'first_event_at'     => $captured->firstEventAt?->format(DATE_ATOM),
            'last_event_at'      => $captured->lastEventAt?->format(DATE_ATOM),
            'binlog_captured_at' => $captured->capturedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @throws FormatUnavailableException when ext-zip / libzip cannot write WinZip AES-256 (no ZipCrypto fallback)
     */
    public static function assertZipAesAvailable(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new FormatUnavailableException('This PHP build cannot write AES-256 zip archives (libzip without a crypto backend). ZipCrypto is never used.');
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
     * @param FileManifest|null $base 1.36+: files unchanged since this manifest are not copied
     * @param bool $track 1.36+: record every collected file in $entries (size, mtime, SHA-256)
     * @param array<string, array{0: int, 1: int, 2: string}> $entries
     * @param int $racyFrom mtimes from this timestamp on are recorded as untrusted
     *
     * @return array<string, string> path => SHA-256 of the files copied into the payload
     */
    private function copyIncludedPaths(string $payloadDir, array $includePaths, int &$skipped, int &$outside, Closure $beat, ?FileManifest $base = null, bool $track = false, array &$entries = [], int $racyFrom = PHP_INT_MAX, int &$reserved = 0): array
    {
        $checksums = [];
        $project   = rtrim($this->projectDir, '/\\');
        $verdicts  = ['secret' => 0, 'outside' => 0, 'reserved' => 0];
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
                $this->collect($relative, $source, $payloadDir, $floor, $verdicts, $checksums, $base, $track, $entries, $racyFrom);
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
                $this->collect($relPath, $full, $payloadDir, $floor, $verdicts, $checksums, $base, $track, $entries, $racyFrom);
                $beat();
            }
        }

        $skipped += $verdicts['secret'];
        $outside += $verdicts['outside'];
        $reserved += $verdicts['reserved'];

        return $checksums;
    }

    /**
     * Copies one collected file into the payload (or, 1.36+, skips it when the base manifest says it is unchanged).
     *
     * Unchanged = same size and mtime as in $base (an untrusted mtime never matches); a file whose content hashes the
     * same as in $base is recorded but not archived. size / mtime are read before the copy: a file that changes while
     * it is copied gets a newer mtime and is collected again next time.
     *
     * @param array<string, true>|null $floor
     * @param array<string, int> $verdicts
     * @param array<string, string> $checksums
     * @param array<string, array{0: int, 1: int, 2: string}> $entries
     */
    private function collect(string $relative, string $absolute, string $payloadDir, ?array &$floor, array &$verdicts, array &$checksums, ?FileManifest $base, bool $track, array &$entries, int $racyFrom): void
    {
        $known = null;
        $size  = 0;
        $mtime = FileManifest::UNTRUSTED_MTIME;
        if ($track) {
            // PP-L9: a project file at a payload path of the bundle would be dropped from every chain restore.
            $verdict = ChainPaths::isReserved($relative) ? 'reserved' : $this->classify($relative, $absolute, $floor);
            if ($verdict !== null) {
                ++$verdicts[$verdict];

                return;
            }
            // classify() resolved the path; a file gone since then fails the copy below.
            $stat  = @stat($absolute) ?: ['size' => 0, 'mtime' => PHP_INT_MAX];
            $size  = (int) $stat['size'];
            $mtime = (int) $stat['mtime'] >= $racyFrom ? FileManifest::UNTRUSTED_MTIME : (int) $stat['mtime'];
            $known = $base?->get($relative);
            if ($known !== null && $mtime !== FileManifest::UNTRUSTED_MTIME && $known[0] === $size && $known[1] === $mtime) {
                $entries[$relative] = $known;

                return;
            }
        }

        $hash    = '';
        $dest    = $payloadDir . '/' . $relative;
        $verdict = $this->copyVerified($relative, $absolute, $dest, $floor, $hash);
        if ($verdict !== null) {
            ++$verdicts[$verdict];

            return;
        }
        if ($track) {
            $entries[$relative] = [$size, $mtime, $hash];
            if ($known !== null && $known[2] === $hash) {
                // Touched, same content: part of the manifest, not of the delta.
                $this->filesystem->remove($dest);

                return;
            }
        }
        $checksums[$relative] = $hash;
    }

    /**
     * EN-L6: classifies the path, opens it once, re-checks the open handle (its inode must be the inode at the
     * file's current real path, which must still be allowed; a hard-linked inode must not be a floor file) and copies
     * from that same handle. A file swapped for a link to a secret between the check and the copy is dropped.
     *
     * @param array<string, true>|null $floor
     *
     * @return string|null the verdict (`secret` / `outside`) when the file is not archived, null when it was copied
     */
    private function copyVerified(string $relative, string $absolute, string $dest, ?array &$floor, string &$hash): ?string
    {
        $verdict = $this->classify($relative, $absolute, $floor);
        if ($verdict !== null) {
            return $verdict;
        }

        $in = @fopen($absolute, 'r');
        if ($in === false) {
            return 'secret';
        }

        try {
            $held    = fstat($in);
            $project = realpath($this->projectDir);
            $real    = realpath($absolute);
            if ($held === false || $project === false || $real === false) {
                return 'secret';
            }
            // One resolved path string: it must be inside the project, allowed, and hold the very inode we opened.
            if (!str_starts_with($real, $project . '/')) {
                return 'outside';
            }
            if ($this->secretPolicy->isForbidden(substr($real, strlen($project) + 1))) {
                return 'secret';
            }
            // lstat: if the resolved path became a link again, its own inode differs from the one we hold.
            $current = @lstat($real);
            if ($current === false || $current['dev'] !== $held['dev'] || $current['ino'] !== $held['ino']) {
                return 'secret';
            }
            // Whatever path games happened (hard links, a parent directory swapped for a link), the inode we hold
            // must not be one of the project's floor files.
            $floor ??= $this->floorInodes($project);
            if (isset($floor[$held['dev'] . ':' . $held['ino']])) {
                return 'secret';
            }

            $this->filesystem->mkdir(dirname($dest), 0o700);
            $out = @fopen($dest, 'w');
            if ($out === false) {
                throw new RuntimeException('Unable to write into the backup staging directory.');
            }
            $context = hash_init('sha256');
            try {
                while (!feof($in)) {
                    $chunk = (string) fread($in, self::STREAM_CHUNK_BYTES);
                    hash_update($context, $chunk);
                    fwrite($out, $chunk);
                }
            } finally {
                fclose($out);
            }
            $hash = hash_final($context);

            return null;
        } finally {
            fclose($in);
        }
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
            return $this->builtinDumper->dump($dumpAbsolute, $options->tableRules, $options->allowUnsafeTableRules, $tick, $options->recordBinlogCoordinates, $options->dumpRoutines);
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
    private function createZip(string $payloadDir, string $target, int $level, ?callable $tick, #[SensitiveParameter] ?ArchivePassword $password = null): void
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
            // WinZip AE-2 AES-256 per entry; never ZipCrypto.
            if ($password !== null && !$zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password->reveal())) {
                $zip->close();
                $this->filesystem->remove($target);

                throw new FormatUnavailableException('zip create failed: AES-256 encryption is not available.');
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
                $plain = @inflate_add($inflate, (string) fread($in, self::INFLATE_SLICE_BYTES), ZLIB_SYNC_FLUSH);
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
     *
     * The size check here only refuses early what the archive admits to: `statIndex()['size']` is what the archive
     * declares, and neither libzip nor the AES HMAC holds an entry to it. {@see self::writeZipEntries()} enforces
     * the limits on the bytes really written.
     */
    private function extractZip(string $archive, string $destination, #[SensitiveParameter] ?ArchivePassword $password = null, bool $allowPlaintext = false): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new FormatUnavailableException('The zip format needs ext-zip.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('zip extract failed: unable to open the archive.');
        }

        $encrypted    = false;
        $plainEntries = false;

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
                $method = $stat['encryption_method'] ?? ZipArchive::EM_NONE;
                if ($method !== ZipArchive::EM_NONE && $method !== ZipArchive::EM_AES_256) {
                    throw new CorruptArchiveException('Only AES-256 encrypted zip entries are accepted (legacy ZipCrypto and AES-128/192 are refused).');
                }
                if ($method === ZipArchive::EM_AES_256) {
                    $encrypted = true;
                } else {
                    $plainEntries = true;
                }
                $opsys = 0;
                $attr  = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0o170000) === 0o120000) {
                    throw new RuntimeException('zip extract failed: symbolic links are not allowed in a backup archive.');
                }
            }

            $this->assertWithinLimits($total, (int) @filesize($archive));
            // EO-M1: AES entries next to plaintext ones are a planted downgrade, refused in every case.
            if ($encrypted && $plainEntries) {
                throw new CorruptArchiveException('The zip mixes AES-256 and plaintext entries.', CorruptArchiveException::NOT_ENCRYPTED);
            }
            // EO-M1, on every path: with a password, only a fully encrypted container is accepted.
            if ($password !== null && !$encrypted && !$allowPlaintext) {
                throw self::plaintextWithPassword();
            }
            if ($encrypted) {
                if ($password === null) {
                    throw new WrongPasswordException('This archive is encrypted: a password is needed.');
                }
                // Every entry's HMAC is checked first, before any byte is written.
                ZipAesAuthenticator::verify($archive, $password, $zip);
                $zip->setPassword($password->reveal());
            }

            // EO-I1: wrong passwords and tampered AES entries were told apart by ZipAesAuthenticator above.
            $this->writeZipEntries($zip, $destination, $this->decompressedLimit((int) @filesize($archive)));
        } finally {
            $zip->close();
        }
    }

    /**
     * Same result as `ZipArchive::extractTo()` in a fresh directory (paths, umask modes, mtimes, no modes from the
     * archive), but through counted streams: nothing past `backup.extract_limits` reaches the disk, whatever the
     * entries declare. A path that already exists, or a link on the way to one, fails the extraction, and a failure
     * removes what this call created and nothing else.
     */
    private function writeZipEntries(ZipArchive $zip, string $destination, int $limit): void
    {
        $written = 0;
        /** @var array<string, true> $created what this call created, parents first */
        $created = [];

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new RuntimeException('zip extract failed.');
                }
                $name     = $stat['name'];
                $segments = array_values(array_filter(explode('/', $name), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
                if (str_ends_with($name, '/')) {
                    self::makeZipDirectories($destination, $segments, $created);
                    continue;
                }
                $file = array_pop($segments);
                if ($file === null) {
                    throw new RuntimeException('zip extract failed.');
                }
                $directory = self::makeZipDirectories($destination, $segments, $created);
                $written += self::writeZipEntry($zip, $i, $stat, $directory . '/' . $file, $limit - $written, $created);
            }
        } catch (Throwable $e) {
            foreach (array_reverse(array_keys($created)) as $path) {
                is_dir($path) ? @rmdir($path) : @unlink($path);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $stat the entry's `statIndex()`
     * @param int $room bytes still allowed by the limits
     * @param array<string, true> $created
     *
     * @return int bytes written
     */
    private static function writeZipEntry(ZipArchive $zip, int $index, array $stat, string $target, int $room, array &$created): int
    {
        // The default password set on $zip applies to the stream.
        $in = @$zip->getStreamIndex($index);
        if (!is_resource($in)) {
            throw new RuntimeException('zip extract failed.');
        }
        // Exclusive: never over a file or through a link that was already there. Only a repeated name reopens a file.
        $out = @fopen($target, isset($created[$target]) ? 'w' : 'x');
        if ($out === false) {
            fclose($in);

            throw new RuntimeException('zip extract failed.');
        }
        $created[$target] = true;
        // A zip stream stops at its last short read, before libzip compares the CRC, so it is compared here. AES-256
        // entries were authenticated by ZipAesAuthenticator, and AE-2 entries carry no CRC.
        $crc     = ($stat['encryption_method'] ?? ZipArchive::EM_NONE) === ZipArchive::EM_NONE ? hash_init('crc32b') : null;
        $written = 0;
        $failed  = false;

        try {
            while (!$failed && !feof($in)) {
                // A read error is only a warning here, and feof() is true after it.
                error_clear_last();
                $chunk  = (string) @fread($in, self::STREAM_CHUNK_BYTES);
                $failed = error_get_last() !== null;
                $written += strlen($chunk);
                if ($written > $room) {
                    throw new UnsafeArchiveException('The decompressed size exceeds backup.extract_limits.');
                }
                $failed = @fwrite($out, $chunk) !== strlen($chunk) || $failed;
                if ($crc !== null) {
                    hash_update($crc, $chunk);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($failed || ($crc !== null && (int) hexdec(hash_final($crc)) !== (int) $stat['crc'])) {
            throw new RuntimeException('zip extract failed.');
        }
        @touch($target, (int) $stat['mtime']);

        return $written;
    }

    /**
     * Creates the missing directories below $destination, refusing a link or a file on the way. 0777 less the umask, as
     * `ZipArchive::extractTo()` does; the bundle extracts under owner-only directories.
     *
     * @param list<string> $segments
     * @param array<string, true> $created receives the directories this call creates, parents first
     *
     * @return string the deepest directory
     */
    private static function makeZipDirectories(string $destination, array $segments, array &$created): string
    {
        $path = $destination;
        foreach ($segments as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException('zip extract failed.');
            }
            if (is_dir($path)) {
                continue;
            }
            if (!@mkdir($path, 0o777)) {
                throw new RuntimeException('zip extract failed.');
            }
            $created[$path] = true;
        }

        return $path;
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

        // EN-L7: the bytes actually written (sparse entries can exceed the stream) are held to the same limit.
        $written = 0;
        foreach ((new Finder())->files()->in($destination)->ignoreDotFiles(false) as $file) {
            $written += (int) $file->getSize();
        }
        $this->assertWithinLimits($written, (int) @filesize($archive));
    }

    /**
     * Size of the decompressed stream of a gzip file, counted in bounded slices (stops past the limit).
     *
     * @param (callable(): void)|null $tick
     */
    private function decompressedSize(string $archive, ?callable $tick): int
    {
        $in = @fopen($archive, 'r');
        if ($in === false) {
            throw new UnsafeArchiveException('The archive cannot be read.');
        }
        $limit   = $this->decompressedLimit((int) @filesize($archive));
        $inflate = inflate_init(ZLIB_ENCODING_GZIP) ?: throw new RuntimeException('gunzip failed: zlib is not available.');
        $total   = 0;
        try {
            while (!feof($in)) {
                $plain = @inflate_add($inflate, (string) fread($in, self::INFLATE_SLICE_BYTES), ZLIB_SYNC_FLUSH);
                if ($plain === false) {
                    throw new UnsafeArchiveException('The archive is not valid gzip data.');
                }
                $total += strlen($plain);
                if ($total > $limit) {
                    break;
                }
                if ($tick !== null) {
                    $tick();
                }
            }
        } finally {
            fclose($in);
        }

        return $total;
    }

    /**
     * EN-L3 / EN-I8: lists the tar first and refuses link, hard-link and device entries, unsafe names, and archives
     * over the size / entry / ratio limits.
     *
     * @param (callable(): void)|null $heartbeat
     */
    private function assertSafeTar(string $archive, ?callable $heartbeat): void
    {
        $entries = 0;
        $listed  = $this->scanTarListing($archive, $heartbeat, function (string $line) use (&$entries): void {
            if (!in_array($line[0], ['-', 'd'], true) || str_contains($line, ' link to ') || str_contains($line, ' -> ')) {
                throw new UnsafeArchiveException('Links and special files are not allowed in a backup archive.');
            }
            $this->countTarEntry($entries);
        });
        if (!$listed) {
            throw new UnsafeArchiveException('The tar.gz archive cannot be listed.');
        }
        // EN-L7: the size is the decompressed tar stream itself, not the listing's size column (an owner name with
        // spaces shifted the columns). The stream bounds what tar writes; extracted bytes are counted again after.
        $this->assertWithinLimits($this->decompressedSize($archive, $heartbeat), (int) @filesize($archive));

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

    /**
     * `tar -tv`, line by line while it runs: the listing is not kept, and a refusal stops tar at once.
     *
     * @param (callable(): void)|null $heartbeat
     * @param callable(string): void $onLine called for each non-empty line; what it throws ends the listing
     *
     * @return bool whether tar could list the archive
     */
    private function scanTarListing(string $archive, ?callable $heartbeat, callable $onLine): bool
    {
        $process = new Process([$this->tarBinary, '-tvzf', $archive]);
        $partial = '';
        $drain   = static function () use ($process, $onLine, &$partial): void {
            $partial .= $process->getIncrementalOutput();
            $process->clearOutput();
            $lines   = explode("\n", $partial);
            $partial = (string) array_pop($lines);
            foreach ($lines as $line) {
                if ($line !== '') {
                    $onLine($line);
                }
            }
        };

        try {
            ProcessDeadline::run($process, $this->processTimeoutSeconds, static function () use ($process, $heartbeat, $drain): void {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                if ($process->isStarted()) {
                    $drain();
                }
            });
            $drain();
            if ($partial !== '') {
                $onLine($partial);
            }
        } finally {
            if ($process->isRunning()) {
                ProcessDeadline::killTree($process);
            }
        }

        return $process->isSuccessful();
    }

    private function countTarEntry(int &$entries): void
    {
        if (++$entries > $this->extractLimits['max_entries']) {
            throw new UnsafeArchiveException('The archive has more entries than backup.extract_limits allows.');
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
