<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use Closure;
use LogicException;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
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
    ) {
        $this->validate();
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
        ];
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
