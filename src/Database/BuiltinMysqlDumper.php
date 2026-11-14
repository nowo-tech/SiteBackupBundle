<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\UnsafeTableRuleException;
use Nowo\SiteBackupBundle\Incremental\DumpCoordinatesParser;
use Nowo\SiteBackupBundle\Incremental\MysqlToolVersion;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function array_filter;
use function count;
use function explode;
use function fclose;
use function fopen;
use function in_array;
use function mb_substr;
use function min;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_copy_to_stream;
use function strlen;
use function strtolower;
use function trim;

/**
 * `backup.database.dumper: builtin` (1.28+): runs `mysqldump` directly into the staging file.
 *
 * - No shell and no PHP in the pipe: `--result-file` writes the dump (the 1.27 nested console re-streamed it and
 *   buffered a plaintext copy in `php://temp`).
 * - Password in `MYSQL_PWD`, never argv; the database name follows `--` ({@see MysqlDumpCommandFactory}).
 * - Table rules: a data pass with `--ignore-table=<db>.<t>` for every ruled table, then (when a table is
 *   structure-only) a `--no-data` pass of those tables appended to the same file. Rules are checked against the live
 *   schema first ({@see TableSafetyChecker}).
 * - `--set-gtid-purged=OFF` with the Oracle MySQL client, so the dump never carries `SET @@GLOBAL.GTID_PURGED`
 *   (which fails on import into a server with history). The MariaDB client does not know the flag.
 */
final class BuiltinMysqlDumper
{
    public const FLAVOUR_MYSQL   = 'mysql';
    public const FLAVOUR_MARIADB = 'mariadb';

    /** Options that would write elsewhere, read credentials or files, or change the output shape. */
    private const FORBIDDEN_OPTIONS = [
        '--result-file', '--password', '--tab', '--log-error', '--defaults-file', '--defaults-extra-file',
        '--defaults-group-suffix', '--plugin-dir', '--init-command', '--xml', '--no-data', '--ignore-table',
        '--all-databases', '--databases', '--tables', '--where',
    ];

    /**
     * @param list<string> $extraOptions `backup.database.extra_options` (each must start with `--`)
     * @param list<string> $requiredTables never skipped nor dumped without rows
     */
    public function __construct(
        private readonly ?string $databaseUrl,
        private readonly string $binary = 'mysqldump',
        private readonly bool $skipSslVerifyServerCert = false,
        private readonly array $extraOptions = [],
        private readonly int $timeoutSeconds = 600,
        private readonly ?SchemaInspectorInterface $schemaInspector = null,
        private readonly array $requiredTables = [],
        private readonly string $migrationsTable = TableSafetyChecker::DEFAULT_MIGRATIONS_TABLE,
        private readonly Filesystem $filesystem = new Filesystem(),
        #[SensitiveParameter]
        private readonly ?string $binlogDatabaseUrl = null,
    ) {
    }

    /**
     * @param array<string, TableRule> $tableRules
     * @param (callable(): void)|null $tick called while the dump runs
     * @param bool $recordBinlogCoordinates 1.36+: `--source-data=2` (+ `--set-gtid-purged=COMMENTED`) on the data pass,
     *                                      parsed into {@see DumpReport::$binlogCoordinates}; needs the Oracle
     *                                      mysqldump 8.0.26+ (the MariaDB client cannot read MySQL binlog coordinates)
     * @param bool $routines 1.36+: `--routines --events` on the data pass (opt-in, chain full backups)
     *
     * @throws DumpFailedException|InvalidBackupOptionsException|UnsafeTableRuleException
     */
    public function dump(string $target, array $tableRules = [], bool $allowUnsafeTableRules = false, ?callable $tick = null, bool $recordBinlogCoordinates = false, bool $routines = false): DumpReport
    {
        self::assertExtraOptions($this->extraOptions);
        if ($recordBinlogCoordinates && $tableRules !== []) {
            throw new InvalidBackupOptionsException('recordBinlogCoordinates cannot be combined with table rules: a binlog replay needs every table.');
        }

        // 1.36+ (PP-M4): a dump that records binlog coordinates needs RELOAD + REPLICATION CLIENT: it runs with the
        // dedicated backup.database.binlog_url account when one is configured, never widening the app's account.
        $url = $recordBinlogCoordinates && $this->binlogDatabaseUrl !== null && $this->binlogDatabaseUrl !== '' ? $this->binlogDatabaseUrl : (string) $this->databaseUrl;
        try {
            $spec     = MysqlDumpCommandFactory::fromDatabaseUrl($url, $this->skipSslVerifyServerCert, $this->binary, $this->extraOptions);
            $database = MysqlDumpCommandFactory::databaseName($url);
            if ($database !== MysqlDumpCommandFactory::databaseName((string) $this->databaseUrl)) {
                throw new InvalidArgumentException('backup.database.binlog_url must name the same database as backup.database.url.');
            }
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('Database dump: ' . $e->getMessage(), 0, $e);
        }

        $warnings = [];
        if ($tableRules !== []) {
            if (!$this->schemaInspector instanceof SchemaInspectorInterface) {
                throw new InvalidBackupOptionsException('Table rules need a schema inspector (backup.database.dumper: builtin).');
            }
            $warnings = TableSafetyChecker::check($tableRules, $this->schemaInspector->inspect(), $this->requiredTables, $allowUnsafeTableRules, $this->migrationsTable);
        }

        [$flavour, $version] = $this->probeClient();
        $gtidOff             = $flavour === self::FLAVOUR_MYSQL;
        if ($recordBinlogCoordinates) {
            if ($flavour !== self::FLAVOUR_MYSQL || !MysqlToolVersion::supports(MysqlToolVersion::oracleClient($version))) {
                throw new InvalidBackupOptionsException(sprintf('recordBinlogCoordinates needs the Oracle mysqldump %s+ (backup.database.binary); the MariaDB client tools cannot read MySQL binlog coordinates.', MysqlToolVersion::MINIMUM));
            }
        }
        // `--set-gtid-purged=COMMENTED` exists from 8.0.32; older 8.0 clients record the coordinates without the GTID set.
        $gtidCommented = MysqlToolVersion::supports(MysqlToolVersion::oracleClient($version), '8.0.32');
        $commands      = self::planCommands($spec['command'], $database, $tableRules, $gtidOff, $recordBinlogCoordinates, $gtidCommented, $routines);

        // Owner-only before mysqldump opens it: the dump is a plaintext copy of the database.
        $this->filesystem->touch($target);
        $this->filesystem->chmod($target, 0o600);

        $structureFile = $target . '.structure';
        try {
            foreach ($commands as $index => $command) {
                $file = $index === 0 ? $target : $structureFile;
                if ($index > 0) {
                    $this->filesystem->touch($file);
                    $this->filesystem->chmod($file, 0o600);
                }
                $this->run(MysqlDumpCommandFactory::withOptions($command, ['--result-file=' . $file]), $spec['env'], $tick);
                if ($index > 0) {
                    $this->append($file, $target);
                }
            }
        } finally {
            $this->filesystem->remove($structureFile);
        }

        $coordinates = null;
        if ($recordBinlogCoordinates) {
            $coordinates = DumpCoordinatesParser::parse($target);
            if ($coordinates === null) {
                throw new DumpFailedException('The dump holds no binlog coordinates: is log_bin on, and does the account have RELOAD and REPLICATION CLIENT?');
            }
        }

        return new DumpReport($flavour, $version, count($commands), $gtidOff, $warnings, $coordinates);
    }

    /**
     * The schema this dumper dumps (1.36+: the database of binlog coordinates and captures).
     *
     * @throws DumpFailedException when the URL is not usable
     */
    public function databaseName(): string
    {
        try {
            return MysqlDumpCommandFactory::databaseName((string) $this->databaseUrl);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('Database dump: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Argv of each pass (pure). Pass 1 dumps everything but the ruled tables; pass 2 (only with structure-only
     * tables) dumps those tables' definitions without rows.
     *
     * @param list<string> $baseCommand from {@see MysqlDumpCommandFactory::fromDatabaseUrl()} (ends with `--`, db)
     * @param array<string, TableRule> $tableRules
     *
     * @return list<list<string>>
     */
    public static function planCommands(array $baseCommand, string $database, array $tableRules, bool $gtidPurgedOff, bool $recordBinlogCoordinates = false, bool $gtidCommented = true, bool $routines = false): array
    {
        $common = $gtidPurgedOff ? ['--set-gtid-purged=OFF'] : [];
        // 1.36+: the data pass records the binlog coordinates of its snapshot (as a comment) and, from 8.0.32, the
        // snapshot's GTID set as a comment too (never executed on import).
        $data = $recordBinlogCoordinates ? ['--source-data=2', $gtidCommented ? '--set-gtid-purged=COMMENTED' : '--set-gtid-purged=OFF'] : $common;
        if ($routines) {
            // 1.36+ (PP-D1-L3, opt-in): stored routines and events too, so a chain restore re-creates them.
            $data = [...$data, '--routines', '--events'];
        }

        $ignore    = [];
        $structure = [];
        foreach ($tableRules as $table => $rule) {
            $ignore[] = '--ignore-table=' . $database . '.' . $table;
            if ($rule === TableRule::StructureOnly) {
                $structure[] = $table;
            }
        }

        $commands = [MysqlDumpCommandFactory::withOptions($baseCommand, [...$data, ...$ignore])];
        if ($structure !== []) {
            $commands[] = [...MysqlDumpCommandFactory::withOptions($baseCommand, [...$common, '--no-data']), ...$structure];
        }

        return $commands;
    }

    /**
     * @param list<string> $options
     *
     * @throws InvalidBackupOptionsException
     */
    public static function assertExtraOptions(array $options): void
    {
        foreach ($options as $option) {
            if ($option === '') {
                continue;
            }
            if (!str_starts_with($option, '--') || preg_match('/[\x00-\x1F\x7F]/', $option) === 1) {
                throw new InvalidBackupOptionsException('backup.database.extra_options entries must be long options ("--name[=value]").');
            }
            // EN-I4: `--loose-` / `--maximum-` prefixes and `_` spellings name the same option.
            $name = str_replace('_', '-', strtolower(explode('=', $option, 2)[0]));
            $name = (string) preg_replace('/^--(?:loose-|maximum-)+/', '--', $name);
            // The MariaDB client accepts unambiguous prefixes (`--pass=`, `--res=`): a prefix of a refused option is refused.
            $isPrefix = strlen($name) >= 5 && array_filter(self::FORBIDDEN_OPTIONS, static fn (string $f): bool => str_starts_with($f, $name)) !== [];
            if (in_array($name, self::FORBIDDEN_OPTIONS, true) || $isPrefix) {
                throw new InvalidBackupOptionsException(sprintf('backup.database.extra_options must not contain %s.', $name));
            }
        }
    }

    /**
     * @return array{0: string, 1: string} flavour, first line of `--version` (≤ 200 chars)
     */
    public function probeClient(): array
    {
        $process = new Process([$this->binary, '--version']);

        try {
            ProcessDeadline::run($process, min(30, $this->timeoutSeconds));
        } catch (ProcessTimedOutException) {
            throw new DumpFailedException('mysqldump --version timed out.');
        }
        if (!$process->isSuccessful()) {
            throw new DumpFailedException(sprintf('Database dump client "%s" is not usable (exit %d).', $this->binary, (int) $process->getExitCode()));
        }

        $version = mb_substr(trim(explode("\n", trim($process->getOutput()))[0]), 0, 200);

        return [str_contains(strtolower($version), 'mariadb') ? self::FLAVOUR_MARIADB : self::FLAVOUR_MYSQL, $version];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @param (callable(): void)|null $tick
     */
    private function run(array $command, array $env, ?callable $tick): void
    {
        $process = new Process($command, null, $env);

        try {
            ProcessDeadline::run($process, $this->timeoutSeconds, $tick);
        } catch (ProcessTimedOutException) {
            // Not chained: Symfony's message embeds the command line.
            throw new DumpFailedException(sprintf('Database dump timed out after %d seconds (process_timeout).', $this->timeoutSeconds));
        }
        if (!$process->isSuccessful()) {
            throw new DumpFailedException('Database dump failed: ' . mb_substr(SecretRedactor::redact(trim($process->getErrorOutput())), 0, 500));
        }
    }

    private function append(string $from, string $to): void
    {
        $in  = fopen($from, 'r');
        $out = fopen($to, 'a');
        if ($in === false || $out === false) {
            throw new DumpFailedException('Unable to append the structure-only pass to the dump.');
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
