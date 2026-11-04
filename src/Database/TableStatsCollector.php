<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function array_map;
use function count;
use function explode;
use function implode;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_replace;
use function strrpos;
use function substr;

/**
 * Row counts and `CHECKSUM TABLE` values of every base table (1.29+), for restore drills.
 *
 * Recorded right after the dump (`BackupOptions::$recordTableStats`) and again on the drill's throwaway schema.
 * Both are full scans: on a busy database, rows written between the dump and the count show up as differences
 * (use the drill's row tolerance or run the drill against a quiet backup).
 */
final class TableStatsCollector
{
    private const NAME = '/^[A-Za-z0-9_$]{1,64}$/';

    public function __construct(
        private readonly ?string $databaseUrl,
        private readonly string $clientBinary = 'mysql',
        private readonly bool $skipSslVerifyServerCert = false,
        private readonly int $timeoutSeconds = 600,
    ) {
    }

    /**
     * @param string|null $schema another schema on the same server (the drill's); null = the configured database
     *
     * @return array<string, array{rows: int, checksum: ?string}> table => stats, sorted by name
     */
    public function collect(?string $schema = null): array
    {
        $tables = [];
        foreach ($this->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", $schema) as $line) {
            if (preg_match(self::NAME, $line) === 1) {
                $tables[] = $line;
            }
        }
        if ($tables === []) {
            return [];
        }

        $stats = [];
        $count = implode(' UNION ALL ', array_map(static fn (string $t): string => sprintf("SELECT '%s', COUNT(*) FROM `%s`", $t, str_replace('`', '', $t)), $tables));
        foreach ($this->query($count, $schema) as $line) {
            $cells = explode("\t", $line);
            if (count($cells) === 2) {
                $stats[$cells[0]] = ['rows' => (int) $cells[1], 'checksum' => null];
            }
        }

        $checksum = 'CHECKSUM TABLE ' . implode(', ', array_map(static fn (string $t): string => '`' . $t . '`', $tables));
        foreach ($this->query($checksum, $schema) as $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== 2) {
                continue;
            }
            $dot   = strrpos($cells[0], '.');
            $table = $dot === false ? $cells[0] : substr($cells[0], $dot + 1);
            if (isset($stats[$table])) {
                $stats[$table]['checksum'] = $cells[1] === 'NULL' ? null : $cells[1];
            }
        }

        return $stats;
    }

    /**
     * @return list<string> output lines
     */
    private function query(string $sql, ?string $schema): array
    {
        try {
            $spec = MysqlDumpCommandFactory::clientFromDatabaseUrl((string) $this->databaseUrl, $this->skipSslVerifyServerCert, $this->clientBinary, ['--batch', '--skip-column-names', '--execute=' . $sql], $schema);
        } catch (InvalidArgumentException $e) {
            throw new DumpFailedException('Table statistics: ' . $e->getMessage(), 0, $e);
        }

        $process = new Process($spec['command'], null, $spec['env']);
        try {
            ProcessDeadline::run($process, $this->timeoutSeconds);
        } catch (ProcessTimedOutException) {
            throw new DumpFailedException('Table statistics timed out.');
        }
        if (!$process->isSuccessful()) {
            throw new DumpFailedException('Table statistics failed: ' . DumpImporter::safeError($process->getErrorOutput()));
        }

        $output = rtrim($process->getOutput(), "\n");

        return $output === '' ? [] : explode("\n", $output);
    }
}
