<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\PrivateTempDirectory;
use Nowo\SiteBackupBundle\Database\MysqlOptionFile;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function count;
use function explode;
use function implode;
use function in_array;
use function mb_substr;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;
use function version_compare;

/**
 * {@see BinlogServerInterface} through the `mysql` client (1.36+): `--defaults-file=<0600 option file>` carries the
 * credentials (removed in a `finally`), `--batch --skip-column-names`, statements in `--execute`. No state between
 * calls (FrankenPHP worker safe).
 */
final class MysqlClientBinlogServer implements BinlogServerInterface
{
    private const VARIABLE_NAME = '/^[a-z_]{1,64}$/';

    /**
     * @param list<string> $tlsOptions `--ssl-*` / `--tls-*` options for the option file (PP-L6)
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly ?string $databaseUrl,
        private readonly string $clientBinary = 'mysql',
        private readonly int $timeoutSeconds = 120,
        private readonly ?string $workDir = null,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly array $tlsOptions = [],
    ) {
    }

    public function version(): string
    {
        return $this->scalar('SELECT VERSION()');
    }

    public function serverUuid(): string
    {
        return strtolower($this->scalar('SELECT @@GLOBAL.server_uuid'));
    }

    public function variables(array $names): array
    {
        $quoted = [];
        foreach ($names as $name) {
            if (preg_match(self::VARIABLE_NAME, $name) !== 1) {
                throw new InvalidArgumentException('Variable names are [a-z_]{1,64}.');
            }
            $quoted[] = "'" . $name . "'";
        }
        if ($quoted === []) {
            return [];
        }

        $values = [];
        foreach ($this->query('SHOW GLOBAL VARIABLES WHERE Variable_name IN (' . implode(', ', $quoted) . ')') as $row) {
            if (count($row) >= 2 && in_array($row[0], $names, true)) {
                $values[$row[0]] = $row[1];
            }
        }

        return $values;
    }

    public function flushBinaryLogs(): void
    {
        $this->query('FLUSH BINARY LOGS');
    }

    public function binaryLogs(): array
    {
        $logs = [];
        foreach ($this->query('SHOW BINARY LOGS') as $row) {
            if (count($row) < 2 || preg_match('/^\d+$/', $row[1]) !== 1) {
                throw new BinlogUnavailableException('SHOW BINARY LOGS returned an unexpected row.');
            }
            $logs[] = ['name' => $row[0], 'size' => (int) $row[1]];
        }

        return $logs;
    }

    public function status(): array
    {
        $statement = self::statusStatement($this->version());
        $rows      = $this->query($statement);
        if ($rows === [] || count($rows[0]) < 2 || preg_match('/^\d+$/', $rows[0][1]) !== 1) {
            throw new BinlogUnavailableException('The server reports no binary log status: is log_bin enabled?');
        }

        return [
            'file'          => $rows[0][0],
            'position'      => (int) $rows[0][1],
            'gtid_executed' => $rows[0][4] ?? '',
        ];
    }

    public function schemas(): array
    {
        $schemas = [];
        foreach ($this->query('SHOW DATABASES') as $row) {
            $schemas[] = $row[0];
        }

        return $schemas;
    }

    /**
     * The objects of a schema as `TYPE name` (TABLE, VIEW, TRIGGER, PROCEDURE, FUNCTION, EVENT), null when the schema
     * does not exist (1.36+, PP-H1 pre-flight of a chain restore).
     *
     * @return list<string>|null
     */
    public function schemaObjects(string $schema): ?array
    {
        $schema = self::schemaName($schema);
        if ($this->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $schema . "'") === []) {
            return null;
        }

        $objects = [];
        foreach ($this->query(
            "SELECT IF(TABLE_TYPE = 'VIEW', 'VIEW', 'TABLE'), TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $schema . "'"
            . " UNION ALL SELECT 'TRIGGER', TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '" . $schema . "'"
            . " UNION ALL SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '" . $schema . "'"
            . " UNION ALL SELECT 'EVENT', EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '" . $schema . "'",
        ) as $row) {
            if (count($row) >= 2) {
                $objects[] = $row[0] . ' ' . $row[1];
            }
        }

        return $objects;
    }

    /**
     * Creates the schema; with $replace, drops it first and keeps its character set and collation (1.36+,
     * `--recreate-database`).
     */
    public function createSchema(string $schema, bool $replace = false): void
    {
        $schema  = self::schemaName($schema);
        $options = '';
        if ($replace) {
            $rows = $this->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $schema . "'");
            if ($rows !== [] && count($rows[0]) >= 2 && preg_match('/^[A-Za-z0-9_]+$/', $rows[0][0] . $rows[0][1]) === 1) {
                $options = ' CHARACTER SET ' . $rows[0][0] . ' COLLATE ' . $rows[0][1];
            }
        }
        $this->query(($replace ? 'DROP DATABASE IF EXISTS `' . $schema . '`; ' : '') . 'CREATE DATABASE `' . $schema . '`' . $options);
    }

    private static function schemaName(string $schema): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $schema) !== 1) {
            throw new InvalidArgumentException('Schema names must match [A-Za-z0-9_]{1,64}.');
        }

        return $schema;
    }

    public function grants(): array
    {
        $grants = [];
        foreach ($this->query('SHOW GRANTS FOR CURRENT_USER()') as $row) {
            $grants[] = $row[0];
        }

        return $grants;
    }

    /**
     * `SHOW BINARY LOG STATUS` from 8.2 (8.4 LTS / 9.x removed `SHOW MASTER STATUS`), `SHOW MASTER STATUS` before.
     */
    public static function statusStatement(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) === 1 && version_compare($m[1] . '.' . $m[2] . '.' . $m[3], '8.2.0', '>=')) {
            return 'SHOW BINARY LOG STATUS';
        }

        return 'SHOW MASTER STATUS';
    }

    private function scalar(string $sql): string
    {
        $rows = $this->query($sql);
        if ($rows === [] || trim($rows[0][0]) === '') {
            throw new BinlogUnavailableException(sprintf('The server returned nothing for "%s".', $sql));
        }

        return trim($rows[0][0]);
    }

    /**
     * @return list<list<string>> rows of tab-separated cells (batch escapes `\n` / `\t`; they are removed)
     */
    private function query(string $sql): array
    {
        $dir = PrivateTempDirectory::createOwned('nowo-site-backup-mysql-', $this->workDir);

        try {
            try {
                $optionFile = MysqlOptionFile::write((string) $this->databaseUrl, $dir, $this->tlsOptions);
            } catch (InvalidArgumentException $e) {
                throw new BinlogUnavailableException('Binary log tools: ' . $e->getMessage(), 0, $e);
            }

            $process = new Process([$this->clientBinary, '--defaults-file=' . $optionFile, '--batch', '--skip-column-names', '--execute=' . $sql]);
            try {
                ProcessDeadline::run($process, $this->timeoutSeconds);
            } catch (ProcessTimedOutException) {
                throw new BinlogUnavailableException(sprintf('The mysql client timed out after %d seconds.', $this->timeoutSeconds));
            }
            if (!$process->isSuccessful()) {
                throw new BinlogUnavailableException(sprintf('"%s" failed: %s', $sql, mb_substr(SecretRedactor::redact(trim($process->getErrorOutput())), 0, 300)));
            }

            $rows = [];
            foreach (explode("\n", str_replace("\r", '', rtrim($process->getOutput(), "\n"))) as $line) {
                if ($line === '') {
                    continue;
                }
                $rows[] = str_replace(['\\n', '\\t'], '', explode("\t", $line));
            }

            return $rows;
        } finally {
            $this->filesystem->remove($dir);
        }
    }
}
