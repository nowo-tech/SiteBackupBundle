<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function count;
use function explode;
use function mb_substr;
use function rtrim;
use function str_replace;
use function trim;

/**
 * {@see SchemaInspectorInterface} over the `mysql` client (`--batch --skip-column-names`), password in `MYSQL_PWD`.
 *
 * Three read-only `information_schema` queries on `DATABASE()`; no user input reaches the SQL.
 */
final class MysqlClientSchemaInspector implements SchemaInspectorInterface
{
    private const SQL_TABLES = 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()';

    private const SQL_FOREIGN_KEYS = 'SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL';

    private const SQL_VIEWS = 'SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()';

    public function __construct(
        private readonly ?string $databaseUrl,
        private readonly string $clientBinary = 'mysql',
        private readonly bool $skipSslVerifyServerCert = false,
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public function inspect(): SchemaSnapshot
    {
        $tables = [];
        $views  = [];
        foreach ($this->query(self::SQL_TABLES) as [$name, $type]) {
            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        $foreignKeys = [];
        foreach ($this->query(self::SQL_FOREIGN_KEYS) as [$from, $to]) {
            $foreignKeys[] = [$from, $to];
        }

        $definitions = [];
        foreach ($this->query(self::SQL_VIEWS) as [$view, $definition]) {
            $definitions[$view] = $definition;
        }

        return SchemaSnapshot::fromRows($tables, $views, $foreignKeys, $definitions);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function query(string $sql): array
    {
        try {
            $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl(
                (string) $this->databaseUrl,
                $this->skipSslVerifyServerCert,
                $this->clientBinary,
                ['--batch', '--skip-column-names', '--execute=' . $sql],
            );
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('Schema inspection: ' . $e->getMessage(), 0, $e);
        }

        $process = new Process($spec['command'], null, $spec['env']);

        try {
            ProcessDeadline::run($process, $this->timeoutSeconds);
        } catch (ProcessTimedOutException) {
            throw new DumpFailedException('Schema inspection timed out.');
        }
        if (!$process->isSuccessful()) {
            throw new DumpFailedException('Schema inspection failed: ' . mb_substr(SecretRedactor::redact(trim($process->getErrorOutput())), 0, 500));
        }

        $rows = [];
        foreach (explode("\n", str_replace("\r", '', rtrim($process->getOutput(), "\n"))) as $line) {
            if ($line === '') {
                continue;
            }
            $cells = explode("\t", $line, 2);
            if (count($cells) === 2) {
                $rows[] = [$cells[0], $cells[1]];
            }
        }

        return $rows;
    }
}
