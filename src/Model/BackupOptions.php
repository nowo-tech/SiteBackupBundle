<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use Closure;
use LogicException;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\BinlogCoordinates;
use Nowo\SiteBackupBundle\Incremental\FileManifest;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use SensitiveParameter;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function trim;

/**
 * Per-call options of {@see SiteBackupManager::createBackup()} (1.28+).
 *
 * Immutable and never serialisable: a Messenger message carries ids, the handler builds the options. Always use named
 * arguments: later minors add arguments, and only names are covered by the BC promise.
 */
final readonly class BackupOptions
{
    public const TABLE_NAME_PATTERN = '/^[A-Za-z0-9_$]{1,64}$/';

    private const EXTRA_META_KEY_PATTERN = '/^[a-z0-9_]{1,40}$/';

    private const EXTRA_META_FORBIDDEN = '/pass|secret|token|key|pwd|dsn|url|credential/i';

    /**
     * @param list<string>|null $includePaths project-relative paths; null = `backup.include_paths`
     * @param array<string, TableRule> $tableRules table name (no schema prefix) => rule; needs the built-in dumper
     * @param int $compressionLevel 1–9 (0 = store, tar.gz / zip only)
     * @param (Closure(BackupProgress): void)|null $heartbeat called regularly during long steps
     * @param bool $allowUnsafeTableRules downgrade the foreign-key / view refusals of a `skip` rule to warnings
     * @param array<string, scalar> $extraMeta copied into the archive meta; keys [a-z0-9_]{1,40}, values ≤ 255 chars,
     *                                         keys that look like secrets are refused
     * @param ArchivePassword|null $password 1.29+: zip → AES-256 (WinZip AE-2) per entry; never stored nor logged
     * @param string|null $keyId 1.29+: opaque id of the password in the host's key ring, recorded in the meta
     * @param bool $recordTableStats 1.29+: record row counts and CHECKSUM TABLE values right after the dump
     *                               (`table_stats` in the meta) for restore drills
     * @param bool $recordBinlogCoordinates 1.36+: the dump records the binlog coordinates of its snapshot (`--source-data=2`)
     *                                      and the backup starts a chain (`type: full`, `chain_id`); needs the
     *                                      built-in dumper and the Oracle MySQL client tools 8.0.26+
     * @param bool $recordFileManifest 1.36+: record the full file manifest (size, mtime, SHA-256) so incrementals can
     *                                 archive only changed files; the backup starts a chain
     * @param FileManifest|null $baseManifest 1.36+ (incremental): archive only files that differ from this manifest, plus
     *                                        `DELETED.json`; usually the parent's `file_manifest`
     * @param BinlogCoordinates|null $binlogFrom 1.36+ (incremental): capture the binary logs from these coordinates
     *                                           instead of dumping the database; usually where the parent ends
     * @param string|null $parentId 1.36+ (incremental): the backup this one continues
     * @param string|null $chainId 1.36+ (incremental): the chain of the parent
     * @param bool $dumpRoutines 1.36+: with recordBinlogCoordinates, also dump stored routines and events
     *                           (`--routines --events`) so chain restores re-create them; the restore account then needs
     *                           SET_ANY_DEFINER for routines of other definers
     */
    public function __construct(
        public bool $includeDatabase = true,
        public bool $includeFiles = true,
        public ?array $includePaths = null,
        public array $tableRules = [],
        public ArchiveFormat $format = ArchiveFormat::TarGz,
        public int $compressionLevel = 6,
        public ?string $label = null,
        public ?string $createdBy = null,
        public ?Closure $heartbeat = null,
        public bool $allowUnsafeTableRules = false,
        public array $extraMeta = [],
        #[SensitiveParameter]
        public ?ArchivePassword $password = null,
        public ?string $keyId = null,
        public bool $recordTableStats = false,
        public bool $recordBinlogCoordinates = false,
        public bool $recordFileManifest = false,
        public ?FileManifest $baseManifest = null,
        public ?BinlogCoordinates $binlogFrom = null,
        public ?string $parentId = null,
        public ?string $chainId = null,
        public bool $dumpRoutines = false,
    ) {
        $this->validate();
        $this->validateChain();
    }

    /**
     * 1.36+: true for an incremental backup (it continues the chain of $parentId).
     */
    public function isIncremental(): bool
    {
        return $this->parentId !== null;
    }

    /**
     * 1.36+: true when the backup carries chain metadata (a chain's full backup or an incremental).
     */
    public function isChainMember(): bool
    {
        return $this->isIncremental() || $this->recordBinlogCoordinates || $this->recordFileManifest;
    }

    /**
     * The 1.27 behaviour of `createBackup($label, $createdBy)`: configured paths and dump, tar.gz, default level.
     */
    public static function fromConfig(?string $label = null, ?string $createdBy = null): self
    {
        return new self(label: $label, createdBy: $createdBy);
    }

    public function withLabel(?string $label): self
    {
        return $this->copy(['label' => $label]);
    }

    public function withCreatedBy(?string $createdBy): self
    {
        return $this->copy(['createdBy' => $createdBy]);
    }

    public function withFormat(ArchiveFormat $format): self
    {
        return $this->copy(['format' => $format]);
    }

    /**
     * @param array<string, TableRule> $tableRules
     */
    public function withTableRules(array $tableRules): self
    {
        return $this->copy(['tableRules' => $tableRules]);
    }

    public function withPassword(#[SensitiveParameter] ?ArchivePassword $password, ?string $keyId = null): self
    {
        return $this->copy(['password' => $password, 'keyId' => $keyId]);
    }

    public function withHeartbeat(?Closure $heartbeat): self
    {
        return $this->copy(['heartbeat' => $heartbeat]);
    }

    /**
     * `database` | `files` | `database_files` (what the caller asked for).
     */
    public function content(): string
    {
        return match (true) {
            $this->includeDatabase && $this->includeFiles => 'database_files',
            $this->includeDatabase                        => 'database',
            default                                       => 'files',
        };
    }

    /**
     * @return array<string, string>
     */
    public function tableRuleValues(): array
    {
        $rules = [];
        foreach ($this->tableRules as $table => $rule) {
            $rules[$table] = $rule->value;
        }

        return $rules;
    }

    /**
     * Safe for logs and events: no closure, no free text beyond the label length.
     *
     * @return array<string, mixed>
     */
    public function toLoggableArray(): array
    {
        return [
            'content'           => $this->content(),
            'include_paths'     => $this->includePaths,
            'table_rules'       => $this->tableRuleValues(),
            'format'            => $this->format->value,
            'compression_level' => $this->compressionLevel,
            'has_label'         => $this->label !== null,
            'allow_unsafe'      => $this->allowUnsafeTableRules,
            'encrypted'         => $this->password !== null,
            'key_id'            => $this->keyId,
        ] + ($this->isChainMember() ? [
            // 1.36+: only for chain backups, so the payload of other backups stays as it was.
            'type'                      => $this->isIncremental() ? 'incremental' : 'full',
            'chain_id'                  => $this->chainId,
            'parent_id'                 => $this->parentId,
            'record_binlog_coordinates' => $this->recordBinlogCoordinates,
            'record_file_manifest'      => $this->recordFileManifest,
        ] : []);
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('BackupOptions cannot be serialised: put ids in messages and build the options in the handler.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('BackupOptions cannot be unserialised.');
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function copy(array $changes): self
    {
        $args = [
            'includeDatabase'       => $this->includeDatabase,
            'includeFiles'          => $this->includeFiles,
            'includePaths'          => $this->includePaths,
            'tableRules'            => $this->tableRules,
            'format'                => $this->format,
            'compressionLevel'      => $this->compressionLevel,
            'label'                 => $this->label,
            'createdBy'             => $this->createdBy,
            'heartbeat'             => $this->heartbeat,
            'allowUnsafeTableRules' => $this->allowUnsafeTableRules,
            'extraMeta'             => $this->extraMeta,
            'password'              => $this->password,
            'keyId'                 => $this->keyId,
            'recordTableStats'      => $this->recordTableStats,
            // 1.36+
            'recordBinlogCoordinates' => $this->recordBinlogCoordinates,
            'recordFileManifest'      => $this->recordFileManifest,
            'baseManifest'            => $this->baseManifest,
            'binlogFrom'              => $this->binlogFrom,
            'parentId'                => $this->parentId,
            'chainId'                 => $this->chainId,
            'dumpRoutines'            => $this->dumpRoutines,
        ];

        /* @phpstan-ignore argument.type (keys are the constructor's parameter names) */
        return new self(...($changes + $args));
    }

    private function validate(): void
    {
        if (!$this->includeDatabase && !$this->includeFiles) {
            throw new InvalidBackupOptionsException('A backup needs the database, the files or both.');
        }

        if ($this->format === ArchiveFormat::SqlGz && ($this->includeFiles || !$this->includeDatabase)) {
            throw new InvalidBackupOptionsException('The sql.gz format holds the database only: set includeFiles to false.');
        }

        $minLevel = $this->format === ArchiveFormat::SqlGz ? 1 : 0;
        if ($this->compressionLevel < $minLevel || $this->compressionLevel > 9) {
            throw new InvalidBackupOptionsException(sprintf('compressionLevel must be between %d and 9.', $minLevel));
        }

        if ($this->tableRules !== [] && !$this->includeDatabase) {
            throw new InvalidBackupOptionsException('Table rules need includeDatabase.');
        }

        foreach ($this->tableRules as $table => $rule) {
            if (!is_string($table) || preg_match(self::TABLE_NAME_PATTERN, $table) !== 1) {
                throw new InvalidBackupOptionsException('Table rule keys must be plain table names ([A-Za-z0-9_$], 1-64 chars).');
            }
            if (!$rule instanceof TableRule) {
                throw new InvalidBackupOptionsException(sprintf('Table rule for "%s" must be a TableRule case.', $table));
            }
        }

        if ($this->includePaths !== null) {
            foreach ($this->includePaths as $path) {
                if (!is_string($path) || str_contains(str_replace('\\', '/', '/' . $path . '/'), '/../') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
                    throw new InvalidBackupOptionsException('includePaths must be project-relative paths without "..".');
                }
            }
        }

        if ($this->keyId !== null && ($this->password === null || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $this->keyId) !== 1)) {
            throw new InvalidBackupOptionsException('keyId needs a password and must match [A-Za-z0-9_.-]{1,64}.');
        }

        self::assertText('label', $this->label, 255);
        self::assertText('createdBy', $this->createdBy, 128);

        foreach ($this->extraMeta as $key => $value) {
            if (!is_string($key) || preg_match(self::EXTRA_META_KEY_PATTERN, $key) !== 1 || preg_match(self::EXTRA_META_FORBIDDEN, $key) === 1) {
                throw new InvalidBackupOptionsException('extraMeta keys must match [a-z0-9_]{1,40} and must not look like secrets.');
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new InvalidBackupOptionsException('extraMeta values must be scalars.');
            }
            if (is_string($value) && mb_strlen($value) > 255) {
                throw new InvalidBackupOptionsException('extraMeta values must be at most 255 characters.');
            }
        }
    }

    private function validateChain(): void
    {
        if ($this->recordBinlogCoordinates && !$this->includeDatabase) {
            throw new InvalidBackupOptionsException('recordBinlogCoordinates needs includeDatabase.');
        }
        if ($this->recordBinlogCoordinates && $this->tableRules !== []) {
            throw new InvalidBackupOptionsException('recordBinlogCoordinates cannot be combined with table rules: a binlog replay needs every table.');
        }
        if ($this->dumpRoutines && !$this->recordBinlogCoordinates) {
            throw new InvalidBackupOptionsException('dumpRoutines applies to the full backup of a chain (recordBinlogCoordinates).');
        }
        if ($this->recordFileManifest && !$this->includeFiles) {
            throw new InvalidBackupOptionsException('recordFileManifest needs includeFiles.');
        }
        if (($this->parentId === null) !== ($this->chainId === null)) {
            throw new InvalidBackupOptionsException('parentId and chainId go together (an incremental continues the chain of its parent).');
        }
        foreach ([$this->parentId, $this->chainId] as $id) {
            if ($id !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $id) !== 1) {
                throw new InvalidBackupOptionsException('parentId and chainId are backup ids ([A-Za-z0-9._-], 1-128 chars).');
            }
        }

        if (!$this->isIncremental()) {
            if ($this->baseManifest instanceof FileManifest || $this->binlogFrom instanceof BinlogCoordinates) {
                throw new InvalidBackupOptionsException('baseManifest and binlogFrom make an incremental backup: set parentId and chainId too.');
            }

            return;
        }

        if ($this->recordBinlogCoordinates || $this->recordFileManifest) {
            throw new InvalidBackupOptionsException('An incremental backup records its coordinates and manifest itself: leave recordBinlogCoordinates and recordFileManifest off.');
        }
        if ($this->includeDatabase !== ($this->binlogFrom instanceof BinlogCoordinates)) {
            throw new InvalidBackupOptionsException('An incremental backup includes the database exactly when binlogFrom is set (the database part is the binary logs).');
        }
        if ($this->includeFiles !== ($this->baseManifest instanceof FileManifest)) {
            throw new InvalidBackupOptionsException('An incremental backup includes files exactly when baseManifest is set (the file part is a delta).');
        }
        if ($this->format === ArchiveFormat::SqlGz) {
            throw new InvalidBackupOptionsException('An incremental backup is a tar.gz or a zip.');
        }
        if ($this->tableRules !== []) {
            throw new InvalidBackupOptionsException('An incremental backup captures every table: table rules do not apply.');
        }
    }

    private static function assertText(string $field, ?string $value, int $max): void
    {
        if ($value === null) {
            return;
        }

        if (trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value) === 1) {
            throw new InvalidBackupOptionsException(sprintf('%s must be 1-%d printable characters.', $field, $max));
        }
    }
}
